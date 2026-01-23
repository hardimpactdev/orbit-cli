#!/usr/bin/env bash
set -euo pipefail

# Orbit Bootstrap Installer
# Usage: curl -fsSL https://raw.githubusercontent.com/hardimpactdev/orbit-cli/main/install.sh | bash

ORBIT_REPO="hardimpactdev/orbit-cli"
ORBIT_INSTALL_DIR="${ORBIT_INSTALL:-$HOME/.local/bin}"
PHP_VERSION="8.4"

# Colors (only if terminal supports it)
if [[ -t 1 ]]; then
    Red='\033[0;31m'
    Green='\033[0;32m'
    Yellow='\033[0;33m'
    Blue='\033[0;34m'
    Dim='\033[0;2m'
    Bold='\033[1m'
    Reset='\033[0m'
else
    Red=''
    Green=''
    Yellow=''
    Blue=''
    Dim=''
    Bold=''
    Reset=''
fi

error() {
    echo -e "${Red}error${Reset}: $*" >&2
    exit 1
}

warn() {
    echo -e "${Yellow}warning${Reset}: $*" >&2
}

info() {
    echo -e "${Dim}$*${Reset}"
}

success() {
    echo -e "${Green}$*${Reset}"
}

step() {
    echo -e "${Blue}==>${Reset} ${Bold}$*${Reset}"
}

# Check for required commands
command -v curl >/dev/null || error "curl is required to install Orbit"
command -v bash >/dev/null || error "bash is required to install Orbit"

# Detect platform
platform=$(uname -s)
arch=$(uname -m)

info "Detected platform: $platform ($arch)"

# ============================================================================
# macOS Installation
# ============================================================================
install_macos() {
    step "Setting up Orbit on macOS"
    
    # 1. Install Homebrew if missing
    if ! command -v brew >/dev/null; then
        step "Installing Homebrew"
        /bin/bash -c "$(curl -fsSL https://raw.githubusercontent.com/Homebrew/install/HEAD/install.sh)"
        
        # Add Homebrew to PATH for this session
        if [[ -f /opt/homebrew/bin/brew ]]; then
            eval "$(/opt/homebrew/bin/brew shellenv)"
        elif [[ -f /usr/local/bin/brew ]]; then
            eval "$(/usr/local/bin/brew shellenv)"
        fi
    else
        success "Homebrew already installed"
    fi
    
    # 2. Install PHP via shivammathur/php tap
    if ! command -v php >/dev/null || ! php -r "exit(version_compare(PHP_VERSION, '8.4', '>=') ? 0 : 1);" 2>/dev/null; then
        step "Installing PHP $PHP_VERSION"
        brew tap shivammathur/php
        brew install "shivammathur/php/php@$PHP_VERSION"
        brew link --overwrite --force "php@$PHP_VERSION"
    else
        success "PHP $(php -r 'echo PHP_VERSION;') already installed"
    fi
    
    # 3. Install Composer if missing
    if ! command -v composer >/dev/null; then
        step "Installing Composer"
        brew install composer
    else
        success "Composer already installed"
    fi
    
    # 4. Install additional tools
    if ! command -v gh >/dev/null; then
        step "Installing GitHub CLI"
        brew install gh
    fi
}

# ============================================================================
# Linux Installation
# ============================================================================
install_linux() {
    step "Setting up Orbit on Linux"
    
    # Check for apt (Ubuntu/Debian only)
    if ! command -v apt-get >/dev/null; then
        error "apt package manager not found. Ubuntu/Debian required."
    fi
    
    # Check for systemd
    if [[ ! -d /etc/systemd ]]; then
        error "systemd not found. Required for service management."
    fi
    
    # 1. Add Ondrej PHP PPA if not already added
    if [[ ! -f /etc/apt/sources.list.d/ondrej-ubuntu-php-*.list ]] && [[ ! -f /etc/apt/sources.list.d/ondrej-php.list ]]; then
        step "Adding PHP repository"
        sudo apt-get update -qq
        sudo apt-get install -y software-properties-common
        sudo add-apt-repository -y ppa:ondrej/php
    fi
    
    # 2. Install PHP if missing or wrong version
    if ! command -v php >/dev/null || ! php -r "exit(version_compare(PHP_VERSION, '8.4', '>=') ? 0 : 1);" 2>/dev/null; then
        step "Installing PHP $PHP_VERSION"
        sudo apt-get update -qq
        sudo apt-get install -y \
            "php$PHP_VERSION-cli" \
            "php$PHP_VERSION-common" \
            "php$PHP_VERSION-curl" \
            "php$PHP_VERSION-zip" \
            "php$PHP_VERSION-mbstring" \
            "php$PHP_VERSION-xml" \
            "php$PHP_VERSION-bcmath"
        
        # Set as default PHP
        sudo update-alternatives --set php "/usr/bin/php$PHP_VERSION" 2>/dev/null || true
    else
        success "PHP $(php -r 'echo PHP_VERSION;') already installed"
    fi
    
    # 3. Install Composer if missing
    if ! command -v composer >/dev/null; then
        step "Installing Composer"
        curl -sS https://getcomposer.org/installer | sudo php -- --install-dir=/usr/local/bin --filename=composer
    else
        success "Composer already installed"
    fi
    
    # 4. Install GitHub CLI if missing
    if ! command -v gh >/dev/null; then
        step "Installing GitHub CLI"
        (type -p wget >/dev/null || sudo apt-get install wget -y) \
            && sudo mkdir -p -m 755 /etc/apt/keyrings \
            && out=$(mktemp) && wget -nv -O$out https://cli.github.com/packages/githubcli-archive-keyring.gpg \
            && cat $out | sudo tee /etc/apt/keyrings/githubcli-archive-keyring.gpg > /dev/null \
            && sudo chmod go+r /etc/apt/keyrings/githubcli-archive-keyring.gpg \
            && echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/githubcli-archive-keyring.gpg] https://cli.github.com/packages stable main" | sudo tee /etc/apt/sources.list.d/github-cli.list > /dev/null \
            && sudo apt-get update -qq \
            && sudo apt-get install gh -y
    fi
}

# ============================================================================
# Download and Install Orbit
# ============================================================================
install_orbit() {
    step "Installing Orbit CLI"
    
    # Create install directory
    mkdir -p "$ORBIT_INSTALL_DIR"
    
    # Get latest release URL
    local latest_url="https://github.com/$ORBIT_REPO/releases/latest/download/orbit.phar"
    local orbit_path="$ORBIT_INSTALL_DIR/orbit"
    
    info "Downloading from $latest_url"
    curl -fsSL -o "$orbit_path" "$latest_url" || error "Failed to download Orbit"
    
    chmod +x "$orbit_path"
    
    success "Orbit installed to $orbit_path"
}

# ============================================================================
# Configure PATH
# ============================================================================
configure_path() {
    local bin_dir="$ORBIT_INSTALL_DIR"
    
    # Check if already in PATH
    if [[ ":$PATH:" == *":$bin_dir:"* ]]; then
        return 0
    fi
    
    step "Configuring PATH"
    
    local shell_name=$(basename "$SHELL")
    local config_file=""
    local export_line="export PATH=\"$bin_dir:\$PATH\""
    
    case "$shell_name" in
        zsh)
            config_file="$HOME/.zshrc"
            ;;
        bash)
            if [[ -f "$HOME/.bash_profile" ]]; then
                config_file="$HOME/.bash_profile"
            else
                config_file="$HOME/.bashrc"
            fi
            ;;
        fish)
            config_file="$HOME/.config/fish/config.fish"
            export_line="set -gx PATH $bin_dir \$PATH"
            ;;
        *)
            warn "Unknown shell: $shell_name. Please add $bin_dir to your PATH manually."
            return 0
            ;;
    esac
    
    # Check if already configured
    if [[ -f "$config_file" ]] && grep -q "$bin_dir" "$config_file" 2>/dev/null; then
        info "PATH already configured in $config_file"
        return 0
    fi
    
    # Add to config file
    if [[ -n "$config_file" ]]; then
        echo "" >> "$config_file"
        echo "# Orbit CLI" >> "$config_file"
        echo "$export_line" >> "$config_file"
        info "Added $bin_dir to PATH in $config_file"
    fi
    
    # Add to current session
    export PATH="$bin_dir:$PATH"
}

# ============================================================================
# Run Orbit Install
# ============================================================================
run_orbit_install() {
    step "Running orbit install"
    echo ""
    
    local orbit_path="$ORBIT_INSTALL_DIR/orbit"
    
    if [[ ! -x "$orbit_path" ]]; then
        error "Orbit not found at $orbit_path"
    fi
    
    # Pass through any arguments
    "$orbit_path" install "$@"
}

# ============================================================================
# Main
# ============================================================================
main() {
    echo ""
    echo -e "${Bold}Orbit Installer${Reset}"
    echo ""
    
    case "$platform" in
        Darwin)
            install_macos
            ;;
        Linux)
            install_linux
            ;;
        *)
            error "Unsupported platform: $platform"
            ;;
    esac
    
    install_orbit
    configure_path
    
    echo ""
    success "Prerequisites installed successfully!"
    echo ""
    
    # Run orbit install
    run_orbit_install "$@"
}

main "$@"

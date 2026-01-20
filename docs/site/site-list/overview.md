# site:list overview

Lists all directories in configured paths as sites, including metadata such as PHP version and domain.

- Uses SiteScanner::scan to load all sites (custom overrides first, then all directories).
- Resolves TLD and default PHP version from configuration.
- Outputs JSON with sites, count, TLD, and default PHP version, or renders a table.
- Indicates whether each site has a public folder, domain, and custom PHP version.

Failure and recovery paths

- If no sites are found, warns and exits successfully.

Inputs and options

- --json

Key integrations

- SiteScanner (filesystem + config + database)
- ConfigManager (TLD, default PHP version)

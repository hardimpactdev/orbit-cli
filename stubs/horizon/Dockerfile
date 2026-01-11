FROM php:8.4-cli-alpine

# Install system dependencies
RUN apk add --no-cache     git     unzip     docker-cli     && docker-php-ext-install pcntl

# Install PHP Redis extension
RUN apk add --no-cache --virtual .build-deps $PHPIZE_DEPS     && pecl install redis     && docker-php-ext-enable redis     && apk del .build-deps

WORKDIR /app

# Healthcheck - verify Horizon is running
HEALTHCHECK --interval=30s --timeout=10s --start-period=10s --retries=3     CMD php artisan horizon:status | grep -q running || exit 1

CMD ["php", "artisan", "horizon"]

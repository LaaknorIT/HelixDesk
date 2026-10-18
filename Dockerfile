# syntax=docker/dockerfile:1

# HelixDesk production image.
# One image runs every role: the web server (default), Horizon and the scheduler.
# See docs/installation.md.

ARG PHP_VERSION=8.5

FROM dunglas/frankenphp:1-php${PHP_VERSION}-bookworm AS base

RUN install-php-extensions \
        intl \
        pcntl \
        pdo_mysql \
        pdo_pgsql \
        redis \
        zip

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# PHP dependencies, without dev packages.
FROM base AS vendor

COPY composer.json composer.lock ./
RUN composer install \
        --no-dev \
        --no-scripts \
        --no-autoloader \
        --prefer-dist \
        --no-interaction \
        --no-progress

# Frontend assets built with Vite.
FROM node:22-bookworm-slim AS assets

WORKDIR /app

COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund

COPY vite.config.js ./
COPY resources ./resources
COPY --from=vendor /app/vendor ./vendor
RUN npm run build

FROM base AS runtime

ENV APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr \
    SERVER_NAME=:8080 \
    PHP_INI_SCAN_DIR=":/usr/local/etc/php/helixdesk"

RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY docker/php.ini /usr/local/etc/php/helixdesk/helixdesk.ini

COPY --from=vendor /app/vendor ./vendor
COPY . .
COPY --from=assets /app/public/build ./public/build

# dump-autoload also runs package discovery and publishes Filament assets.
RUN composer dump-autoload --optimize --no-dev --no-interaction \
    && mkdir -p \
        storage/app/public \
        storage/framework/cache/data \
        storage/framework/sessions \
        storage/framework/views \
        storage/logs \
    && php artisan storage:link --no-ansi \
    && chown -R www-data:www-data storage bootstrap/cache /data /config

COPY --chmod=755 docker/entrypoint.sh /usr/local/bin/helixdesk-entrypoint

USER www-data

EXPOSE 8080

HEALTHCHECK --interval=15s --timeout=5s --start-period=60s --retries=5 \
    CMD ["helixdesk-entrypoint", "healthcheck"]

ENTRYPOINT ["helixdesk-entrypoint"]
CMD ["web"]

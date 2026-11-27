# syntax=docker/dockerfile:1.7
# ---------------------------------------------------------------------------
# Nexora Commerce (Bagisto 2.5) — production image for Render
#   nginx + PHP-FPM 8.4 under supervisord, listening on Render's $PORT.
#   No secrets in this file: everything sensitive comes from Render env vars.
# ---------------------------------------------------------------------------

ARG PHP_VERSION=8.4.27

# --- 1. Front-end build (root Vite bundle; theme bundles ship prebuilt) -----
FROM node:22-bookworm-slim AS assets
WORKDIR /app
ENV PLAYWRIGHT_SKIP_BROWSER_DOWNLOAD=1
COPY package.json vite.config.js ./
COPY resources ./resources
COPY public ./public
RUN npm install --no-audit --no-fund && npm run build

# --- 2. PHP dependencies ------------------------------------------------------
FROM composer:2 AS composer

FROM php:${PHP_VERSION}-fpm-bookworm AS app

RUN apt-get update && apt-get install -y --no-install-recommends \
        nginx supervisor gettext-base unzip git ca-certificates \
        libfreetype6-dev libjpeg62-turbo-dev libpng-dev libwebp-dev \
        libicu-dev libzip-dev libgmp-dev libxml2-dev libonig-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j"$(nproc)" \
        bcmath calendar exif gd gmp intl mbstring opcache pdo_mysql soap sockets zip \
    && apt-get purge -y --auto-remove libfreetype6-dev libjpeg62-turbo-dev libpng-dev libwebp-dev libicu-dev libzip-dev libgmp-dev libxml2-dev libonig-dev \
    && apt-get install -y --no-install-recommends libfreetype6 libjpeg62-turbo libpng16-16 libwebp7 libicu72 libzip4 libgmp10 libxml2 libonig5 \
    && rm -rf /var/lib/apt/lists/* /etc/nginx/sites-enabled/default

COPY --from=composer /usr/bin/composer /usr/bin/composer
COPY deploy/render/php.ini /usr/local/etc/php/conf.d/zz-nexora.ini
COPY deploy/render/php-fpm.conf /usr/local/etc/php-fpm.d/zz-nexora.conf
COPY deploy/render/nginx.conf.template /etc/nginx/nexora.conf.template
COPY deploy/render/supervisord.conf /etc/supervisor/conf.d/nexora.conf
COPY deploy/render/start.sh /usr/local/bin/nexora-start
RUN chmod +x /usr/local/bin/nexora-start

WORKDIR /var/www/html

# Install vendor first so code edits reuse the cached layer.
COPY composer.json composer.lock ./
COPY packages ./packages
RUN composer install --no-dev --no-interaction --no-progress --prefer-dist --no-scripts --no-autoloader

COPY . .
COPY --from=assets /app/public/build ./public/build

RUN composer dump-autoload --no-dev --optimize --classmap-authoritative \
    && rm -f bootstrap/cache/*.php \
    && php artisan package:discover --ansi \
    && mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R ug+rwX storage bootstrap/cache

ENV APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr \
    PORT=10000

EXPOSE 10000
CMD ["nexora-start"]

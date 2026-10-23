# syntax=docker/dockerfile:1.7
#
# Production image for Coolify (Build Pack = Dockerfile). It replaces the
# Nixpacks build, whose PHP is frozen at an old nixpkgs snapshot (8.4.2,
# Dec 2024) and cannot take the October 2026 PHP security fixes.
#
# PHP_VERSION is pinned on purpose: a floating tag could be served from a
# cached old layer. Raise it on every PHP security release (the Docker image
# appears within a day of the release) and redeploy - see docs/deployment.md.
ARG PHP_VERSION=8.4.27

# ---------------------------------------------------------------------------
# 1. PHP runtime: official php-fpm image + the extensions the app needs + nginx
# ---------------------------------------------------------------------------
FROM php:${PHP_VERSION}-fpm-bookworm AS php-base

COPY --from=ghcr.io/mlocati/php-extension-installer:2 /usr/bin/install-php-extensions /usr/local/bin/
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# default-mysql-client provides `mysqldump`, which the database backups
# (App\Services\DatabaseBackupService) need; on Debian it is the MariaDB client.
RUN apt-get update \
    && apt-get install -y --no-install-recommends ca-certificates curl default-mysql-client gosu nginx procps supervisor unzip \
    && install-php-extensions bcmath exif gd imagick intl opcache pcntl pdo_mysql zip \
    && rm -rf /var/lib/apt/lists/* /tmp/* \
    && cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

# ---------------------------------------------------------------------------
# 2. Composer dependencies (cached until composer.json / composer.lock change)
# ---------------------------------------------------------------------------
FROM php-base AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN --mount=type=cache,target=/root/.composer/cache \
    composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction

# ---------------------------------------------------------------------------
# 3. Frontend assets (Vite). The Filament theme CSS imports files from vendor/.
# ---------------------------------------------------------------------------
FROM node:22-bookworm-slim AS assets
WORKDIR /app
ARG SOURCE_COMMIT
ENV SOURCE_COMMIT=${SOURCE_COMMIT}
COPY package.json package-lock.json ./
RUN --mount=type=cache,target=/root/.npm npm ci
COPY --from=vendor /app/vendor ./vendor
COPY . .
RUN npm run build

# ---------------------------------------------------------------------------
# 4. Final image
# ---------------------------------------------------------------------------
FROM php-base AS app
WORKDIR /app

COPY docker/php.ini "$PHP_INI_DIR/conf.d/99-app.ini"
COPY docker/php-fpm-pool.conf /usr/local/etc/php-fpm.d/zzz-app.conf
COPY docker/nginx.conf /etc/nginx/nginx.conf.template
COPY docker/supervisord.conf /etc/supervisor/conf.d/app.conf
COPY docker/entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh \
    && rm -f /etc/nginx/sites-enabled/default /etc/nginx/conf.d/default.conf

COPY --from=vendor /app/vendor ./vendor
COPY . .
COPY --from=assets /app/public/build ./public/build

# The same steps composer's post-autoload-dump scripts run, with caches that
# need no database or Redis while the image is built.
RUN mkdir -p storage/app/public storage/app/private storage/framework/cache/data storage/framework/sessions \
       storage/framework/views storage/framework/testing storage/logs bootstrap/cache \
    && composer dump-autoload --optimize --no-dev --no-scripts \
    && CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync DB_CONNECTION=sqlite DB_DATABASE=:memory: \
       php artisan package:discover --ansi \
    && CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync DB_CONNECTION=sqlite DB_DATABASE=:memory: \
       php artisan filament:upgrade \
    && chown -R www-data:www-data storage bootstrap/cache

# Port nginx listens on; set the same value as "Ports Exposes" in Coolify.
ENV PORT=80
EXPOSE 80

HEALTHCHECK --interval=30s --timeout=5s --start-period=30s --retries=3 \
    CMD curl -fsS "http://127.0.0.1:${PORT}/health.html" >/dev/null || exit 1

ENTRYPOINT ["docker-entrypoint.sh"]

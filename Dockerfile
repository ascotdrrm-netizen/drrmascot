# ASCOT DRRMS on Render (free web service) with Supabase for the database
# and private file storage. See docs/RENDER_SUPABASE.md.

# ---- 1. PHP packages (no dev tools) ------------------------------------------
# The composer image has a different PHP build; the final image below has every
# extension the lock file needs, so platform checks are skipped only here.
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist \
        --no-interaction --ignore-platform-reqs
COPY . .
RUN composer dump-autoload --no-dev --optimize --no-scripts

# ---- 2. CSS, JS, icons -------------------------------------------------------
# Tailwind scans Laravel's pagination views in vendor/, so the packages above
# are copied in before the build. prebuild also fills public/vendor and
# resources/icons/material (read by <x-icon> at render time).
FROM node:22-bookworm-slim AS assets
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY . .
COPY --from=vendor /app/vendor ./vendor
RUN npm run build

# ---- 3. Runtime: Apache + PHP 8.3 --------------------------------------------
FROM php:8.3-apache-bookworm

COPY --from=mlocati/php-extension-installer:2 /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions pdo_pgsql pgsql gd zip intl bcmath gmp exif opcache

# 7-Zip ("7z"): backups are .7z files locked with a password, names included.
RUN apt-get update \
    && apt-get install -y --no-install-recommends p7zip-full \
    && rm -rf /var/lib/apt/lists/*

RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY docker/php.ini "$PHP_INI_DIR/conf.d/zz-app.ini"

# Render sends traffic to $PORT (10000 unless it says otherwise).
ENV PORT=10000
COPY docker/apache.conf /etc/apache2/sites-available/000-default.conf
RUN a2enmod rewrite headers \
    && sed -i 's/^Listen 80$/Listen ${PORT}/' /etc/apache2/ports.conf \
    && printf '<IfModule mpm_prefork_module>\n  MaxRequestWorkers 6\n</IfModule>\n' \
        > /etc/apache2/conf-enabled/zz-workers.conf

WORKDIR /var/www/html
COPY --chown=www-data:www-data . .
COPY --from=vendor --chown=www-data:www-data /app/vendor ./vendor
COPY --from=assets --chown=www-data:www-data /app/public/build ./public/build
COPY --from=assets --chown=www-data:www-data /app/public/vendor ./public/vendor
COPY --from=assets --chown=www-data:www-data /app/resources/icons ./resources/icons

RUN mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views \
        storage/app/private storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && runuser -u www-data -- php artisan package:discover --ansi

COPY docker/start.sh /usr/local/bin/start
RUN chmod +x /usr/local/bin/start
CMD ["start"]

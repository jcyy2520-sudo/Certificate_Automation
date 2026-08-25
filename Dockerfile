# syntax=docker/dockerfile:1

# ---------------------------------------------------------------------------
# Stage 1 — front-end assets
# ---------------------------------------------------------------------------
# Vite writes public/build/manifest.json, which @vite() reads at runtime. A
# missing manifest is a 500 on every page, so the build runs here and the
# result is copied into the runtime image rather than built on the server.
FROM node:22-alpine AS assets

WORKDIR /app

COPY package.json package-lock.json ./
RUN npm ci

COPY vite.config.js ./
COPY resources ./resources
RUN npm run build


# ---------------------------------------------------------------------------
# Stage 2 — PHP dependencies
# ---------------------------------------------------------------------------
FROM composer:2 AS vendor

WORKDIR /app

# Install against the lock file with no scripts: artisan is not yet present and
# package discovery must not run before the application code is copied in.
COPY composer.json composer.lock ./
RUN composer install \
        --no-dev \
        --no-scripts \
        --no-autoloader \
        --prefer-dist \
        --no-interaction \
        --no-progress

COPY . .
RUN composer dump-autoload --optimize --classmap-authoritative --no-dev


# ---------------------------------------------------------------------------
# Stage 3 — runtime
# ---------------------------------------------------------------------------
FROM php:8.3-fpm-alpine AS runtime

# nginx + supervisor let one container serve HTTP and run the queue workers and
# scheduler. That keeps the whole system on a single hosting service; split the
# workers out later by setting RUN_WORKERS=false here and running dedicated
# services with the same image.
RUN apk add --no-cache \
        nginx \
        supervisor \
        postgresql-client \
        icu-libs \
        libpng \
        libjpeg-turbo \
        freetype \
        libzip

# Build dependencies are installed, used, then dropped in the same layer so the
# compiler toolchain never ships in the final image.
RUN apk add --no-cache --virtual .build-deps \
        $PHPIZE_DEPS \
        icu-dev \
        libpng-dev \
        libjpeg-turbo-dev \
        freetype-dev \
        libzip-dev \
        postgresql-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        pdo_pgsql \
        gd \
        intl \
        zip \
        bcmath \
        opcache \
    # phpredis is the faster Redis client. predis ships in composer.json as the
    # portable fallback, so the image works either way; REDIS_CLIENT selects.
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del .build-deps

WORKDIR /var/www/html

# Application code first, then the build artefacts on top. .dockerignore keeps
# the local vendor/ and public/build/ out, but this order means the compiled
# results win even if that file ever drifts.
COPY . .
COPY --from=vendor /app/vendor ./vendor
COPY --from=assets /app/public/build ./public/build

COPY docker/php.ini /usr/local/etc/php/conf.d/zz-app.ini
COPY docker/nginx.conf /etc/nginx/nginx.conf
COPY docker/supervisord.conf /etc/supervisord.conf
COPY docker/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint

# php-fpm and nginx both run as www-data; only these two trees are written to.
RUN mkdir -p storage/framework/{cache/data,sessions,testing,views} \
             storage/logs \
             storage/app/private \
             bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R ug+rwX storage bootstrap/cache

# The platform supplies $PORT; entrypoint substitutes it into the nginx config.
ENV PORT=8080
EXPOSE 8080

ENTRYPOINT ["entrypoint"]

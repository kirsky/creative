FROM php:8.5-fpm

ARG APP_UID=1000
ARG APP_GID=1000
ARG UPLOAD_DIR=/var/uploads

# fileinfo, sodium and opcache already ship with the official image; the build
# fails below if any required extension is missing.
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        git \
        unzip \
        libicu-dev \
        libfreetype-dev \
        libjpeg-dev \
        libpng-dev \
        libwebp-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql intl gd \
    && rm -rf /var/lib/apt/lists/* \
    && php -r 'foreach (["pdo_mysql", "intl", "sodium", "fileinfo", "gd", "Zend OPcache"] as $e) { if (!extension_loaded($e)) { fwrite(STDERR, "missing extension: $e\n"); exit(1); } }'

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY php.ini /usr/local/etc/php/conf.d/zz-app.ini

# Run as an unprivileged user matching the host user, so files written into the
# bind-mounted backend/ belong to you. Without root the pool's user/group are moot.
RUN groupadd -o -g "${APP_GID}" app \
    && useradd -o -m -u "${APP_UID}" -g "${APP_GID}" -s /bin/bash app \
    && sed -i '/^user = /d; /^group = /d' /usr/local/etc/php-fpm.d/www.conf \
    && mkdir -p "${UPLOAD_DIR}" /var/www/backend \
    && chown app:app "${UPLOAD_DIR}" /var/www/backend

ENV COMPOSER_HOME=/home/app/.composer

USER app
WORKDIR /var/www/backend

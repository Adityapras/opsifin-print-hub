# Image dev untuk app, database queue worker, dan scheduler (lihat compose.yaml).
FROM php:8.5-cli

COPY --from=mlocati/php-extension-installer:latest /usr/bin/install-php-extensions /usr/local/bin/
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

RUN install-php-extensions pdo_mysql intl zip pcntl bcmath opcache \
    && apt-get update \
    && apt-get install -y --no-install-recommends git unzip default-mysql-client osslsigncode msitools \
    && rm -rf /var/lib/apt/lists/*

COPY uploads.ini /usr/local/etc/php/conf.d/zz-uploads.ini

ENV COMPOSER_HOME=/tmp/composer

WORKDIR /app

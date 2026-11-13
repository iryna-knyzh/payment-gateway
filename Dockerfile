# Development image. Production would use php-fpm + nginx, opcache and `composer install --no-dev`.
FROM php:8.3-cli-alpine

RUN apk add --no-cache postgresql-dev git unzip \
    && docker-php-ext-install pdo_pgsql

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

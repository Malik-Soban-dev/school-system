FROM node:24-bookworm-slim AS frontend

WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci --ignore-scripts
COPY resources ./resources
COPY vite.config.js ./
RUN npm run build

FROM php:8.3-apache

WORKDIR /var/www/html

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip libzip-dev libsqlite3-dev \
    && docker-php-ext-install pdo_sqlite zip \
    && rm -rf /var/lib/apt/lists/*

RUN a2enmod rewrite headers \
    && sed -i 's/Listen 80/Listen 10000/' /etc/apache2/ports.conf \
    && sed -i 's/:80>/:10000>/; s!/var/www/html!/var/www/html/public!g' /etc/apache2/sites-available/000-default.conf
COPY config/apache-school.conf /etc/apache2/conf-available/school.conf
RUN a2enconf school

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --prefer-dist --no-scripts --no-autoloader

COPY . .
COPY --from=frontend /app/public/build ./public/build

RUN mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views \
    && touch database/database.sqlite \
    && composer dump-autoload --no-dev --optimize \
    && php artisan storage:link \
    && chown -R www-data:www-data storage bootstrap/cache \
    && apache2ctl -t

EXPOSE 10000

CMD sh -c "php artisan migrate --force && chown -R www-data:www-data storage bootstrap/cache && exec apache2-foreground"

FROM php:8.3-cli

WORKDIR /var/www/html

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip libzip-dev libsqlite3-dev nodejs npm \
    && docker-php-ext-install pdo_sqlite zip \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader

COPY package.json ./
RUN npm install --ignore-scripts

COPY . .

RUN mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views \
    && touch database/database.sqlite \
    && npm run build \
    && php artisan package:discover --ansi \
    && php artisan storage:link

EXPOSE 10000

CMD sh -c "php artisan migrate --force && php artisan serve --host=0.0.0.0 --port=\${PORT:-10000}"

FROM php:8.3-fpm 
 
RUN apt-get update 
 
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer 
 
WORKDIR /var/www/html 
COPY . . 
 
RUN composer install --no-dev --optimize-autoloader 
 
RUN chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache 
 
EXPOSE 8080 
CMD php artisan serve --host=0.0.0.0 --port=8080

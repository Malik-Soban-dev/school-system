FROM php:8.2-fpm 
 
RUN apt-get update 
 
RUN curl -sS https://getcomposer.org 
 
WORKDIR /var/www/html 
COPY . . 
 
RUN composer install --no-dev --optimize-autoloader 
 
RUN chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache 
 
EXPOSE 8080 
CMD php artisan serve --host=0.0.0.0 --port=8080

FROM php:8.2-apache

RUN docker-php-ext-install pdo pdo_mysql

RUN a2enmod rewrite

COPY . /var/www/html/

RUN chown -R www-data:www-data /var/www/html/sessions && \
    chmod -R 755 /var/www/html/sessions

RUN chmod -R 755 /var/www/html

RUN rm /var/www/html/Dockerfile

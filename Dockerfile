FROM php:8.2-apache

RUN apt-get update && apt-get install -y libcurl4-openssl-dev && \
    docker-php-ext-install pdo pdo_mysql curl && \
    a2enmod rewrite

COPY . /var/www/html/

RUN chown -R www-data:www-data /var/www/html && \
    find /var/www/html -type d -exec chmod 755 {} \; && \
    find /var/www/html -type f -exec chmod 644 {} \;

EXPOSE 80
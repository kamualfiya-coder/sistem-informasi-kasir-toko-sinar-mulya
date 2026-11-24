FROM php:8.3-apache

RUN docker-php-ext-install mysqli

RUN a2enmod rewrite

COPY . /var/www/html/

RUN mkdir -p /etc/ssl/certs \
    && mv /var/www/html/tidb-ca.pem /etc/ssl/certs/tidb-ca.pem

EXPOSE 80
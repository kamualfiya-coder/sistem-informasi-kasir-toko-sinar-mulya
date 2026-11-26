FROM php:8.5.13-apache

# Install kebutuhan PHP Laravel dan koneksi MySQL/TiDB
RUN apt-get update && apt-get install -y \
    libicu-dev \
    libzip-dev \
    unzip \
    && docker-php-ext-install pdo_mysql intl zip \
    && a2enmod rewrite \
    && rm -rf /var/lib/apt/lists/*

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Salin isi backend Laravel
COPY backend/ /var/www/html/

# Salin sertifikat SSL TiDB


# Atur Apache agar membuka folder public Laravel
RUN sed -i 's/Listen 80/Listen 10000/' /etc/apache2/ports.conf \
    && sed -i 's/<VirtualHost \*:80>/<VirtualHost *:10000>/' /etc/apache2/sites-available/000-default.conf \
    && sed -i 's|DocumentRoot /var/www/html|DocumentRoot /var/www/html/public|' /etc/apache2/sites-available/000-default.conf \
    && sed -i '/DocumentRoot \/var\/www\/html\/public/a\\    <Directory /var/www/html/public>\n        AllowOverride All\n        Require all granted\n    </Directory>' /etc/apache2/sites-available/000-default.conf

# Install dependency Laravel
RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader

# Atur izin folder Laravel
RUN chown -R www-data:www-data storage bootstrap/cache

EXPOSE 10000

CMD ["sh", "-c", "printf '%s' \"$MYSQL_CA_CONTENT\" > /etc/ssl/certs/tidb-ca.pem && chmod 644 /etc/ssl/certs/tidb-ca.pem && export MYSQL_ATTR_SSL_CA=/etc/ssl/certs/tidb-ca.pem && exec apache2-foreground"]
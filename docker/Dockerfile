FROM php:8.2-apache

# PDO MySQL extension
RUN docker-php-ext-install pdo pdo_mysql

# Enable mod_rewrite for .htaccess routing
RUN a2enmod rewrite

# Upload dir
RUN mkdir -p /var/www/html/uploads/wtp && chmod 777 /var/www/html/uploads/wtp

COPY apache.conf /etc/apache2/sites-available/000-default.conf

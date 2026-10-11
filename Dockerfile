FROM php:8.2-apache

# Postgres driver for PDO + Apache modules
RUN apt-get update && apt-get install -y libpq-dev unzip git \
 && docker-php-ext-install pdo pdo_pgsql \
 && a2enmod rewrite headers \
 && rm -rf /var/lib/apt/lists/*

# Let .htaccess files work
RUN sed -i 's/AllowOverride None/AllowOverride All/' /etc/apache2/apache2.conf

# Composer (installs vlucas/phpdotenv, since vendor/ isn't in git)
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# The app folder becomes the website root, so /login.php works
WORKDIR /var/www/html
COPY TechPrime-AI/ /var/www/html/
RUN composer install --no-dev --optimize-autoloader --no-interaction \
 && mkdir -p uploads/products assets/profiles \
 && chown -R www-data:www-data uploads assets/profiles

FROM php:8.1-fpm

# Install system deps and PHP extensions commonly needed
RUN apt-get update && apt-get install -y \
    git unzip libzip-dev libonig-dev libpng-dev libjpeg-dev libfreetype6-dev \
    && docker-php-ext-configure gd --with-jpeg --with-freetype \
    && docker-php-ext-install pdo_mysql mbstring zip gd

# Install composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Create app directory
WORKDIR /var/www/html

# Copy php.ini
COPY .docker/php/php.ini /usr/local/etc/php/php.ini

# Set user and permissions safely
RUN useradd -o -u 1000 -g www-data -m developer || true
RUN chown -R developer:www-data /var/www/html

USER developer

EXPOSE 9000

CMD ["php-fpm"]

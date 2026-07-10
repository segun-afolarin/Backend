FROM php:8.3-cli

RUN apt-get update && apt-get install -y \
    git \
    unzip \
    zip \
    libzip-dev \
    && docker-php-ext-install zip pdo pdo_mysql

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www

COPY . .

# Custom PHP settings — raises upload_max_filesize from the 2M default
# so photo evidence uploads (up to 5MB per Laravel's validation rule)
# actually reach Laravel instead of being silently rejected by PHP itself.
COPY php.ini /usr/local/etc/php/conf.d/custom.ini

RUN composer install --no-dev --optimize-autoloader

EXPOSE 10000

CMD php artisan config:clear && php artisan migrate --force && php artisan serve --host=0.0.0.0 --port=10000
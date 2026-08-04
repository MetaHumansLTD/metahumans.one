FROM php:8.3-apache

ARG APP_ROLE=hub

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip libzip-dev libxml2-dev libssl-dev \
    && docker-php-ext-install pdo pdo_mysql dom \
    && a2enmod rewrite headers \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY composer.json ./
RUN composer install --no-dev --no-interaction --no-scripts --prefer-dist

COPY . .

RUN mkdir -p /var/www/html/var \
    && chown -R www-data:www-data /var/www/html \
    && printf "<VirtualHost *:80>\n    DocumentRoot /var/www/html/public\n    <Directory /var/www/html/public>\n        AllowOverride All\n        Require all granted\n        FallbackResource /index.php\n    </Directory>\n    SetEnv APP_ROLE ${APP_ROLE}\n    ErrorLog /proc/self/fd/2\n    CustomLog /proc/self/fd/1 combined\n</VirtualHost>\n" > /etc/apache2/sites-available/000-default.conf

EXPOSE 80

CMD ["apache2-foreground"]

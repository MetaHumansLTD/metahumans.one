FROM php:8.3-apache

# Enable the PHP extensions and Apache modules used by the existing app.
RUN apt-get update \
    && apt-get install -y --no-install-recommends libcurl4-openssl-dev libonig-dev libzip-dev \
    && docker-php-ext-install -j"$(nproc)" curl mysqli pdo_mysql mbstring zip \
    && a2enmod rewrite headers expires proxy proxy_http proxy_wstunnel ssl \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html

# The application expects the cPanel web root to be `public_html`.
COPY public_html/ /var/www/html/

# Mirror the session location configured in `.user.ini`.
RUN mkdir -p /data/sessions \
    && chown -R www-data:www-data /data/sessions /var/www/html

EXPOSE 80

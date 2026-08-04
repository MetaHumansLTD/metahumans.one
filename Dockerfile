FROM php:8.3-apache

# Enable the PHP extensions and Apache modules used by the existing app.
RUN apt-get update \
    && apt-get install -y --no-install-recommends curl git libcurl4-openssl-dev libonig-dev libzip-dev unzip \
    && docker-php-ext-install -j"$(nproc)" curl mysqli pdo_mysql mbstring zip \
    && a2enmod rewrite headers expires proxy proxy_http proxy_wstunnel ssl \
    && curl -fsSL https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html

# The application expects the cPanel web root to be `public_html`.
COPY public_html/ /var/www/html/
COPY apps/ /var/www/apps/
COPY .data/config/ /data/config/
COPY .data/security/app.key /data/security/app.key
COPY docker-entrypoint-mh.sh /usr/local/bin/docker-entrypoint-mh.sh

RUN if [ -f "/var/www/html/gear/domain-registrars/composer.json" ]; then \
        composer install --working-dir=/var/www/html/gear/domain-registrars --no-dev --no-interaction --prefer-dist --optimize-autoloader; \
    fi

# Mirror the session location configured in `.user.ini`.
RUN mkdir -p /data/sessions \
    && chown -R www-data:www-data /data/sessions /var/www/html /var/www/apps \
    && chmod +x /usr/local/bin/docker-entrypoint-mh.sh

EXPOSE 80
ENTRYPOINT ["/usr/local/bin/docker-entrypoint-mh.sh"]
CMD ["apache2-foreground"]

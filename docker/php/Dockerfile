FROM php:8.2-fpm

RUN apt-get update && apt-get install -y \
    git curl zip unzip \
    libpq-dev libzip-dev libicu-dev libonig-dev \
    libfreetype6-dev libjpeg62-turbo-dev libpng-dev libwebp-dev \
    libxml2-dev libcurl4-openssl-dev \
    && rm -rf /var/lib/apt/lists/*

RUN docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j$(nproc) \
        pdo pdo_pgsql pgsql zip intl mbstring gd xml curl opcache bcmath sockets

RUN pecl install redis && docker-php-ext-enable redis

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY php.ini /usr/local/etc/php/conf.d/custom.ini

WORKDIR /app

EXPOSE 9000
CMD ["php-fpm"]

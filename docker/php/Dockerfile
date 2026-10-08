FROM php:8.3-fpm-alpine

RUN apk add --no-cache \
        icu-libs libzip libpq \
        # pdftotext: extrae texto de PDF respetando espacios mejor que smalot/pdfparser
        poppler-utils \
        # gd: tarjeta con el resultado del reto para compartir (fuentes en backend/resources/fonts)
        freetype libpng libjpeg-turbo \
    && apk add --no-cache --virtual .build-deps \
        $PHPIZE_DEPS icu-dev libzip-dev postgresql-dev linux-headers \
        freetype-dev libpng-dev libjpeg-turbo-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        pdo_pgsql pgsql intl zip bcmath pcntl opcache gd \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del .build-deps

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY php.ini /usr/local/etc/php/conf.d/zz-app.ini

WORKDIR /var/www/html

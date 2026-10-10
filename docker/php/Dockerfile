# Se construye desde la raíz del repositorio (contexto "."):
#   base     → PHP con extensiones; en desarrollo el código se monta desde tu carpeta.
#   app      → producción: código, dependencias y assets del panel dentro de la imagen.
#   web      → producción: Caddy interno (HTTP) con el chat compilado y lo público de Laravel.
#              El HTTPS lo pone el Caddy de la puerta del servidor (ver docker/produccion/sitio.caddy).

FROM php:8.3-fpm-alpine AS base

RUN apk add --no-cache \
        icu-libs libzip libpq \
        # pdftotext: extrae texto de PDF respetando espacios mejor que smalot/pdfparser
        poppler-utils \
    && apk add --no-cache --virtual .build-deps \
        $PHPIZE_DEPS icu-dev libzip-dev postgresql-dev linux-headers \
    && docker-php-ext-install -j"$(nproc)" \
        pdo_pgsql pgsql intl zip bcmath pcntl opcache \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del .build-deps

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY docker/php/php.ini /usr/local/etc/php/conf.d/zz-app.ini

WORKDIR /var/www/html


# ---------- producción ----------

FROM node:22-alpine AS chat
WORKDIR /app
COPY frontend/package.json frontend/package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY frontend/ ./
# Antes de publicar Consulta Vial: URL a la que se manda a quien abra el chat sin incrustar.
ARG VITE_SOLO_INCRUSTADO=""
ENV VITE_SOLO_INCRUSTADO=$VITE_SOLO_INCRUSTADO
RUN npm run build


FROM base AS app
COPY docker/php/produccion.ini /usr/local/etc/php/conf.d/zz-produccion.ini
COPY --chown=www-data:www-data backend/ /var/www/html/
# --no-dev: sin herramientas de pruebas. El script post-autoload-dump publica los assets de Filament.
RUN composer install --no-dev --optimize-autoloader --no-interaction --no-progress \
    && chown -R www-data:www-data /var/www/html \
    && rm -rf /root/.composer
USER www-data


FROM caddy:2-alpine AS web
COPY docker/produccion/Caddyfile /etc/caddy/Caddyfile
COPY --from=chat /app/dist /srv/chat
# Caddy revisa que index.php y los assets del panel existan en su propio disco.
COPY --from=app /var/www/html/public /var/www/html/public

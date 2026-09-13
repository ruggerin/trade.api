# Imagem de produção do Trade Teste API (Laravel 12) — php-fpm + nginx no mesmo container,
# supervisionados pelo supervisord. Postgres e storage (S3) são serviços externos.

# --- Stage 1: assets do frontend (Vite), só pra gerar public/build ---
FROM node:20-alpine AS assets
WORKDIR /app
COPY package.json package-lock.json* ./
RUN npm install
COPY resources ./resources
COPY vite.config.js ./
RUN npm run build

# --- Stage 2: dependências PHP ---
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
COPY app ./app
COPY bootstrap ./bootstrap
COPY config ./config
COPY database ./database
COPY routes ./routes
COPY artisan ./artisan
RUN composer install --no-dev --optimize-autoloader --no-interaction --no-scripts

# --- Stage 3: imagem final ---
FROM php:8.2-fpm-alpine

RUN apk add --no-cache \
        nginx \
        supervisor \
        postgresql-client \
        icu-libs \
        libzip \
        oniguruma \
    && apk add --no-cache --virtual .build-deps \
        postgresql-dev \
        libzip-dev \
        icu-dev \
        oniguruma-dev \
    && docker-php-ext-install pdo pdo_pgsql pgsql bcmath intl zip opcache mbstring \
    && apk del .build-deps \
    && rm -rf /var/cache/apk/*

WORKDIR /var/www/html

COPY . .
COPY --from=vendor /app/vendor ./vendor
COPY --from=assets /app/public/build ./public/build

RUN mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && php artisan package:discover --ansi

COPY deploy/nginx.conf /etc/nginx/http.d/default.conf
COPY deploy/supervisord.conf /etc/supervisor.d/supervisord.ini
COPY deploy/docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

EXPOSE 8080

ENTRYPOINT ["docker-entrypoint.sh"]
CMD ["supervisord", "-c", "/etc/supervisor.d/supervisord.ini", "-n"]

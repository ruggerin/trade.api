# syntax=docker/dockerfile:1
#
# Container único da API (PHP-FPM + Nginx + cron do scheduler, via supervisor) — pensado pra
# rodar sozinho numa VPS, ao lado de outras aplicações já existentes (por isso a porta do host
# é configurável, ver docker-compose.yml). Ver docs/02-API-BACKEND.md pro resto do contrato da
# API; este arquivo só cuida de empacotar o que já existe, não muda nenhuma regra de negócio.

# ---- Stage 1: dependências PHP via Composer --------------------------------------------------
# Imagem oficial do Composer já traz o binário pronto. --no-scripts: os scripts do
# composer.json (key:generate, migrate, npm build — pensados pro `composer run setup` local, ver
# README.md) não fazem sentido rodando aqui, sem banco nem .env ainda; isso fica por conta do
# entrypoint.sh na imagem final. --ignore-platform-reqs: o Composer roda NESTE container
# (que não tem pdo_pgsql/etc. instalado ainda), as extensões só importam na imagem final.
FROM composer:2 AS vendor

WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --ignore-platform-reqs --prefer-dist

COPY . .
RUN composer dump-autoload --no-dev --optimize --classmap-authoritative

# ---- Stage 2: imagem final (PHP-FPM + Nginx + Supervisor + cron) ----------------------------
FROM php:8.2-fpm-alpine AS runtime

# postgresql-dev/oniguruma-dev/libzip-dev: só cabeçalhos pra COMPILAR as extensões abaixo —
# removidos depois, não ficam na imagem final. supervisor: gerencia php-fpm + nginx + cron no
# mesmo container (mais simples que 3 containers só pra esta API numa VPS única). dcron: cron do
# Alpine, dispara o scheduler do Laravel (Schedule::command em routes/console.php — geração
# automática de OS por campanha/agenda/contrato, ver docs/07-ORDEM-DE-SERVICO.md e
# docs/10-AGENDA-VISITA.md — sem isso rodando, essas features silenciosamente nunca disparam).
# postgresql-libs/libzip/oniguruma: bibliotecas de RUNTIME das extensões abaixo — diferente das
# suas contrapartes -dev (só cabeçalho, saem com .build-deps), estas ficam na imagem final ou a
# extensão compilada não carrega ("Unable to load dynamic library ... No such file or
# directory"), erro só visível rodando o container, não durante o build.
RUN apk add --no-cache \
        nginx \
        supervisor \
        dcron \
        postgresql-libs \
        libzip \
        oniguruma \
    && apk add --no-cache --virtual .build-deps \
        postgresql-dev \
        oniguruma-dev \
        libzip-dev \
    && docker-php-ext-install -j"$(nproc)" pdo_pgsql pgsql bcmath mbstring opcache zip \
    && apk del .build-deps

WORKDIR /var/www/html

# Código primeiro (respeitando .dockerignore — vendor/ nunca vem daqui), vendor por cima depois,
# sempre o compilado no estágio anterior (nunca um vendor/ de dev que porventura exista local).
COPY . .
COPY --from=vendor /app/vendor ./vendor
# bootstrap/cache/*.php (packages.php/services.php) não é versionado — é o manifesto de
# provedores gerado pelo `composer install` de quem quer que tenha rodado localmente por
# último, ISSO INCLUI pacotes de dev (ex.: laravel/pail) que não existem no vendor --no-dev
# acima. Sobrepõe pelo manifesto que o próprio estágio "vendor" já gerou (via
# post-autoload-dump → package:discover) contra o vendor certo — senão o boot quebra tentando
# instanciar um ServiceProvider de um pacote que não está nesta imagem.
COPY --from=vendor /app/bootstrap/cache ./bootstrap/cache

COPY docker/nginx.conf /etc/nginx/http.d/default.conf
COPY docker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf
COPY docker/php.ini /usr/local/etc/php/conf.d/99-app.ini
COPY docker/crontab /etc/crontabs/root
COPY docker/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh && chmod 0600 /etc/crontabs/root

EXPOSE 80

ENTRYPOINT ["/entrypoint.sh"]
CMD ["/usr/bin/supervisord", "-n", "-c", "/etc/supervisor/conf.d/supervisord.conf"]

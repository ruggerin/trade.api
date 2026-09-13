#!/bin/sh
set -e

echo "Aguardando banco de dados em ${DB_HOST}:${DB_PORT}..."
attempts=0
max_attempts=60
until pg_isready -h "${DB_HOST}" -p "${DB_PORT:-5432}" -U "${DB_USERNAME}" -q 2>/dev/null; do
  attempts=$((attempts + 1))
  if [ "$attempts" -ge "$max_attempts" ]; then
    echo "Banco de dados não respondeu após ${max_attempts} tentativas (DB_HOST=${DB_HOST} DB_PORT=${DB_PORT})."
    exit 1
  fi
  sleep 1
done
echo "Banco de dados disponível."

# Garante que a pasta de storage/cache existe com permissão correta (volume pode vir vazio).
mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache

php artisan storage:link --force || true

echo "Rodando migrations..."
php artisan migrate --force

php artisan config:cache
php artisan route:cache
php artisan view:cache

exec "$@"

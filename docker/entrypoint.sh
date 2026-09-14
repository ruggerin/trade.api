#!/bin/sh
set -e

# Um volume novo/vazio (ver docker-compose.yml, api_storage) não tem a árvore de pastas que o
# Laravel espera dentro de storage/ — cria antes de qualquer coisa tentar escrever nelas. Sem
# isso, o primeiro request quebra com "failed to open stream" assim que o framework tenta logar
# ou cachear algo.
mkdir -p \
    storage/app/private \
    storage/app/public \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs \
    bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache

# APP_KEY nunca é gerado aqui de propósito: gerar um novo a cada boot invalidaria toda sessão e
# token existente a cada restart/deploy. Precisa vir definido em api/.env (ou nas env vars do
# container) — gere uma vez com "php artisan key:generate --show" e cole o valor lá.
if [ -z "$APP_KEY" ]; then
    echo "[entrypoint] ERRO: APP_KEY não está definido. Rode 'php artisan key:generate --show'" >&2
    echo "[entrypoint] localmente e cole o valor (APP_KEY=base64:...) no seu api/.env antes de subir." >&2
    exit 1
fi

php artisan config:cache
php artisan route:cache
php artisan view:cache

# Desligável via RUN_MIGRATIONS=false (docker-compose.yml) pra quem preferir rodar migration na
# mão (ex.: primeiro deploy, conferir antes de aplicar).
#
# SEM --isolated de propósito: essa flag tranca via Cache::lock(), que aqui usa CACHE_STORE=
# database — ou seja, a trava depende da tabela cache_locks, que só existe DEPOIS que as
# migrations rodarem pela primeira vez. Num banco novo/vazio (primeiro deploy, exatamente o caso
# de subir num servidor novo) isso é uma referência circular: migrate --isolated falha tentando
# usar uma trava que a própria migration ainda não criou (SQLSTATE 42P01, relation "cache_locks"
# does not exist). Sem problema abrir mão da trava aqui — é uma única instância desta API por
# VPS, não uma frota de réplicas migrando ao mesmo tempo.
if [ "${RUN_MIGRATIONS:-true}" = "true" ]; then
    # O profile "db" facultativo (docker-compose.yml) sobe api+db juntos sem ordem garantida —
    # o Postgres pode ainda estar inicializando (initdb, primeiro boot) quando o entrypoint já
    # chega aqui. Espera até 60s por uma conexão de verdade antes de tentar migrar, em vez de
    # falhar na primeira tentativa por "connection refused".
    echo "[entrypoint] Aguardando o banco aceitar conexão..."
    tentativas=0
    until php artisan db:show > /dev/null 2>&1; do
        tentativas=$((tentativas + 1))
        if [ "$tentativas" -ge 30 ]; then
            echo "[entrypoint] ERRO: banco não respondeu depois de 60s (DB_HOST=${DB_HOST}, DB_PORT=${DB_PORT})." >&2
            exit 1
        fi
        sleep 2
    done

    echo "[entrypoint] Rodando migrations (RUN_MIGRATIONS=${RUN_MIGRATIONS:-true})..."
    php artisan migrate --force
else
    echo "[entrypoint] RUN_MIGRATIONS=false — pulando migrations, rode 'docker compose exec api php artisan migrate --force' na mão quando quiser."
fi

exec "$@"

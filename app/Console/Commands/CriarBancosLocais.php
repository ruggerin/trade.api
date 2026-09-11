<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use PDO;
use PDOException;

/**
 * Setup local: cria os bancos Postgres usados pelo projeto, se ainda não existirem — o de
 * desenvolvimento (DB_DATABASE do .env) e o de teste (usado pelo phpunit.xml, ver
 * docs/06-PENDENCIAS.md). Não roda migration nenhuma, só garante que os bancos existem pra
 * "php artisan migrate" e "php artisan test" funcionarem logo em seguida.
 *
 * Motivo de existir: as migrations usam `pgcrypto`/`gen_random_uuid()` (específico do
 * Postgres) — sqlite não roda esse schema, então o app inteiro depende de um Postgres real
 * rodando localmente, e isso não tinha nenhum passo automatizado até aqui.
 */
class CriarBancosLocais extends Command
{
    protected $signature = 'dev:criar-bancos
        {--teste=pdv_test : Nome do banco de teste — precisa bater com o DB_DATABASE configurado em phpunit.xml}';

    protected $description = 'Cria os bancos Postgres locais de desenvolvimento e teste, se ainda não existirem';

    public function handle(): int
    {
        $host = config('database.connections.pgsql.host');
        $port = config('database.connections.pgsql.port');
        $usuario = config('database.connections.pgsql.username');
        $senha = config('database.connections.pgsql.password');
        $bancoDev = config('database.connections.pgsql.database');
        $bancoTeste = $this->option('teste');

        try {
            // Conecta na base de manutenção "postgres" (sempre existe) — não dá pra conectar
            // direto no banco que ainda vamos criar.
            $pdo = new PDO("pgsql:host={$host};port={$port};dbname=postgres", $usuario, $senha);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        } catch (PDOException $e) {
            $this->error("Não consegui conectar no Postgres em {$host}:{$port} — {$e->getMessage()}");
            $this->error('Confira DB_HOST/DB_PORT/DB_USERNAME/DB_PASSWORD no seu .env e se o Postgres está rodando.');

            return self::FAILURE;
        }

        foreach (array_unique([$bancoDev, $bancoTeste]) as $banco) {
            $this->criarSeNaoExistir($pdo, $banco);
        }

        $this->newLine();
        $this->info('Pronto. Agora rode: php artisan migrate');

        return self::SUCCESS;
    }

    private function criarSeNaoExistir(PDO $pdo, string $banco): void
    {
        if (! preg_match('/^[a-zA-Z0-9_]+$/', $banco)) {
            $this->error("Nome de banco inválido (só letras/números/underscore): {$banco}");

            return;
        }

        $stmt = $pdo->prepare('SELECT 1 FROM pg_database WHERE datname = ?');
        $stmt->execute([$banco]);

        if ($stmt->fetchColumn()) {
            $this->line("- {$banco}: já existe, nada a fazer.");

            return;
        }

        // CREATE DATABASE não aceita parâmetro bind (não é uma query normal) — seguro aqui
        // porque o nome já passou pelo regex acima, nunca é input livre de usuário final.
        $pdo->exec("CREATE DATABASE \"{$banco}\"");
        $this->info("- {$banco}: criado.");
    }
}

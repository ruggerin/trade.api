<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adesão = USO, não login — docs/52-LOG-DE-ACESSO-E-ADESAO.md §2 (revisado). O token do Sanctum
 * não expira: o promotor loga uma vez e fica semanas logado, então "último login" diria que quem
 * usa todo dia "sumiu". Aqui entra uma linha por usuário × dia × app (MOBILE/ADMIN) na primeira
 * requisição autenticada do dia (App\Http\Middleware\RegistrarAcessoDiario). `data` é o dia no
 * fuso da empresa (docs/50 §4.3).
 *
 * Já nasce com histórico: dias com login (`usuario_login_logs`, desde 08/09) e dias com visita
 * iniciada pelo promotor — os dois são uso real do sistema.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('acessos_diarios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('usuario_id')->constrained('usuarios')->cascadeOnDelete();
            $table->foreignId('empresa_id')->nullable()->constrained('empresas')->cascadeOnDelete();
            // Cópia no momento do acesso — se o papel mudar depois, o histórico não se reescreve.
            $table->string('user_type', 20);
            $table->string('app', 10);
            $table->date('data');
            // Primeiro acesso daquele dia naquele app.
            $table->timestamp('created_at')->nullable();

            $table->unique(['usuario_id', 'data', 'app']);
            $table->index(['empresa_id', 'data']);
        });

        // Histórico a partir do que já existe. Dia no fuso da empresa (SUPERADMIN: Brasília).
        $diaLocal = fn (string $coluna) => "({$coluna} AT TIME ZONE 'UTC' AT TIME ZONE COALESCE(e.fuso, 'America/Sao_Paulo'))::date";

        DB::statement("
            INSERT INTO acessos_diarios (usuario_id, empresa_id, user_type, app, data, created_at)
            SELECT u.id, u.empresa_id, u.user_type,
                   CASE WHEN l.dispositivo_identificador IS NOT NULL OR u.user_type = 'PROMOTOR' THEN 'MOBILE' ELSE 'ADMIN' END,
                   {$diaLocal('l.created_at')}, MIN(l.created_at)
            FROM usuario_login_logs l
            JOIN usuarios u ON u.id = l.usuario_id
            LEFT JOIN empresas e ON e.id = u.empresa_id
            WHERE l.created_at IS NOT NULL
            GROUP BY 1, 2, 3, 4, 5
            ON CONFLICT (usuario_id, data, app) DO NOTHING
        ");

        DB::statement("
            INSERT INTO acessos_diarios (usuario_id, empresa_id, user_type, app, data, created_at)
            SELECT u.id, u.empresa_id, u.user_type, 'MOBILE', {$diaLocal('v.inicio_data')}, MIN(v.inicio_data)
            FROM visitas v
            JOIN usuarios u ON u.id = v.usuario_id
            LEFT JOIN empresas e ON e.id = u.empresa_id
            WHERE v.inicio_data IS NOT NULL
            GROUP BY 1, 2, 3, 4, 5
            ON CONFLICT (usuario_id, data, app) DO NOTHING
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('acessos_diarios');
    }
};

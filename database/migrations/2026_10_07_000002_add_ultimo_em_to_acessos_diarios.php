<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Horário do último acesso do dia (docs/52) — a lista de Usuários mostra data e hora, não só
 * "há N dias". `created_at` continua sendo o primeiro acesso do dia. Linhas antigas herdam o
 * `created_at` (o melhor horário que existe pra elas).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('acessos_diarios', function (Blueprint $table) {
            $table->timestamp('ultimo_em')->nullable();
        });

        DB::statement('UPDATE acessos_diarios SET ultimo_em = created_at WHERE ultimo_em IS NULL');
    }

    public function down(): void
    {
        Schema::table('acessos_diarios', function (Blueprint $table) {
            $table->dropColumn('ultimo_em');
        });
    }
};

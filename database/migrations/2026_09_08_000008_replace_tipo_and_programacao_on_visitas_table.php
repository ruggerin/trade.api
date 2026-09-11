<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `visitas.tipo` (PROGRAMADA/NAO_PROGRAMADA) e `programacao_inicio`/`programacao_fim`/
 * `programacao_usuario_id` nunca chegaram a ser escritos por nenhum controller — o mecanismo de
 * agendamento passou a viver inteiro em `OrdemServico` (ver docs/07-ORDEM-DE-SERVICO.md), que é
 * uma entidade separada (uma OS pode existir sem nunca virar visita). Removendo aqui em vez de
 * manter os dois mecanismos em paralelo — sem risco de perda de dado real, essas colunas nunca
 * tiveram valor de negócio gravado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visitas', function (Blueprint $table): void {
            $table->dropColumn(['tipo', 'programacao_inicio', 'programacao_fim']);
            $table->dropConstrainedForeignId('programacao_usuario_id');
            $table->foreignId('ordem_servico_id')->nullable()->after('campanha_id')->constrained('ordens_servico');
        });
    }

    public function down(): void
    {
        Schema::table('visitas', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('ordem_servico_id');
            $table->string('tipo', 20)->nullable();
            $table->timestamp('programacao_inicio')->nullable();
            $table->timestamp('programacao_fim')->nullable();
            $table->foreignId('programacao_usuario_id')->nullable()->constrained('usuarios');
        });
    }
};

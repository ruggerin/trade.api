<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Troca o campo livre `nivel_exibicao` (varchar, herdado do sistema antigo sem regra nenhuma)
 * por uma relação de verdade com `niveis_exibicao` — cada empresa cadastra e mantém a própria
 * lista de níveis, em vez de digitar texto solto a cada produto. Sem dado real em produção
 * ainda (app em desenvolvimento), então troca direta em vez de migração de dado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('produtos_auditoria', function (Blueprint $table): void {
            $table->dropColumn('nivel_exibicao');
            $table->foreignId('nivel_exibicao_id')->nullable()->after('secao_id')->constrained('niveis_exibicao');
        });
    }

    public function down(): void
    {
        Schema::table('produtos_auditoria', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('nivel_exibicao_id');
            $table->string('nivel_exibicao')->nullable();
        });
    }
};

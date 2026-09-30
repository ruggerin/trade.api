<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Resumo do afastamento durante a visita (docs/49-AFASTAMENTO-DURANTE-VISITA.md §6, fase 2) —
 * gravado pelo comando visitas:calcular-afastamento um tempo depois do checkout. Colunas nulas com
 * `afastamento_calculado_em` preenchido = sem posição na janela, não dá pra afirmar nada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visitas', function (Blueprint $table) {
            $table->unsignedSmallInteger('afastamento_qtd')->nullable();
            $table->unsignedInteger('afastamento_minutos')->nullable();
            $table->unsignedInteger('afastamento_max_metros')->nullable();
            $table->timestamp('afastamento_calculado_em')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('visitas', function (Blueprint $table) {
            $table->dropColumn(['afastamento_qtd', 'afastamento_minutos', 'afastamento_max_metros', 'afastamento_calculado_em']);
        });
    }
};

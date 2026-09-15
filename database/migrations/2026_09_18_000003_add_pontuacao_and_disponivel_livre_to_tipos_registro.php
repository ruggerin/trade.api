<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 3 de docs/20-FORMULARIO-DINAMICO-CAMPANHA.md (decisões 5 e 8).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tipos_registro', function (Blueprint $table): void {
            // % de campos BOOLEANO/SORTIMENTO que "passaram" sobre o total do formulário —
            // exposto em VisitaRegistroResource::pontuacao quando ligado. V1 sem peso por
            // pergunta (decisão 5).
            $table->boolean('usa_pontuacao')->default(false)->after('eh_alerta');
            // Controla se o tipo aparece solto no dropdown de "criar registro" do promotor —
            // decisão 8. Tipos nascidos de dentro da tela de Campanha (Fase 3) já nascem com
            // isso desligado por padrão.
            $table->boolean('disponivel_registro_livre')->default(true)->after('usa_pontuacao');
        });
    }

    public function down(): void
    {
        Schema::table('tipos_registro', function (Blueprint $table): void {
            $table->dropColumn(['usa_pontuacao', 'disponivel_registro_livre']);
        });
    }
};

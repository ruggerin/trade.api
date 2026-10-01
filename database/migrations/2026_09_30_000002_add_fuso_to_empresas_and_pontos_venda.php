<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fuso horário (IANA) — docs/50-SUPORTE-MULTIPLOS-FUSOS-HORARIOS.md §4. A empresa define o corte
 * de "dia" (§4.3); a loja, o horário marcado (§4.2) — nula = herda o da empresa. O instante em si
 * continua sempre UTC no banco; isto só diz como interpretar "dia" e "hora marcada".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('empresas', function (Blueprint $table) {
            $table->string('fuso', 64)->default('America/Sao_Paulo');
        });

        Schema::table('pontos_venda', function (Blueprint $table) {
            $table->string('fuso', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('empresas', function (Blueprint $table) {
            $table->dropColumn('fuso');
        });

        Schema::table('pontos_venda', function (Blueprint $table) {
            $table->dropColumn('fuso');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tema do admin escolhido pelo próprio usuário — docs/65-TEMA-ESCURO.md. `claro`, `escuro` ou
 * `sistema` (segue o sistema operacional). Null = nunca escolheu = claro.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('usuarios', function (Blueprint $table) {
            $table->string('tema', 10)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('usuarios', function (Blueprint $table) {
            $table->dropColumn('tema');
        });
    }
};

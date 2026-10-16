<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // docs/82 §6.1 — tela "Onde é este registro?": o que a lista mostra e se o promotor pode
        // filtrar a marca depois da seção/departamento. Só valem com algum escolhe_* ligado.
        Schema::table('tipos_registro', function (Blueprint $table): void {
            // PRIMEIRO_DA_LOJA (padrão) | SO_DA_LOJA
            $table->string('lista_recorte', 20)->default('PRIMEIRO_DA_LOJA')->after('escolhe_secao');
            $table->boolean('filtra_marca')->default(false)->after('lista_recorte');
        });
    }

    public function down(): void
    {
        Schema::table('tipos_registro', function (Blueprint $table): void {
            $table->dropColumn(['lista_recorte', 'filtra_marca']);
        });
    }
};

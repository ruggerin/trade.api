<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Foto enviada depois do registro, uma por vez (POST /visitas/{v}/registros/{r}/imagens):
        // a chave vem do app e torna o reenvio seguro quando a resposta se perde em rede lenta.
        Schema::table('imagens_registro', function (Blueprint $table): void {
            $table->string('idempotency_key', 100)->nullable();
            $table->unique(['visita_id', 'idempotency_key']);
        });
    }

    public function down(): void
    {
        Schema::table('imagens_registro', function (Blueprint $table): void {
            $table->dropUnique(['visita_id', 'idempotency_key']);
            $table->dropColumn('idempotency_key');
        });
    }
};

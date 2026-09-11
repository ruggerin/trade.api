<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Pivot pura (sem uuid/empresa_id próprios) — mesmo padrão de marcas_departamentos,
        // ver docs/01-MODELO-DE-DADOS.md. Isolamento herdado de usuario_id/ponto_venda_id.
        // Define quais lojas cada promotor atende: restringe o que ele vê no mobile e onde
        // pode fazer check-in (ver docs/02-API-BACKEND.md, regra de negócio 6).
        Schema::create('promotor_pontos_venda', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('usuario_id')->constrained('usuarios');
            $table->foreignId('ponto_venda_id')->constrained('pontos_venda');
            $table->timestamps();

            $table->unique(['usuario_id', 'ponto_venda_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promotor_pontos_venda');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Filtros multi-escolha do Direcionamento (promotor/loja/rede), cada um opcional — ausência de
// linha numa categoria = sem restrição naquela dimensão. Combinação: E entre categorias
// diferentes, OU dentro da mesma categoria (docs/25-DIRECIONAMENTO-ORDEM-SERVICO.md §2 decisão 2).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('direcionamento_pontos_venda', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('direcionamento_id')->constrained('direcionamentos')->cascadeOnDelete();
            $table->foreignId('ponto_venda_id')->constrained('pontos_venda')->cascadeOnDelete();
            $table->unique(['direcionamento_id', 'ponto_venda_id']);
        });

        Schema::create('direcionamento_redes_loja', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('direcionamento_id')->constrained('direcionamentos')->cascadeOnDelete();
            $table->foreignId('rede_loja_id')->constrained('redes_lojas')->cascadeOnDelete();
            $table->unique(['direcionamento_id', 'rede_loja_id']);
        });

        Schema::create('direcionamento_promotores', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('direcionamento_id')->constrained('direcionamentos')->cascadeOnDelete();
            $table->foreignId('usuario_id')->constrained('usuarios')->cascadeOnDelete();
            $table->unique(['direcionamento_id', 'usuario_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('direcionamento_promotores');
        Schema::dropIfExists('direcionamento_redes_loja');
        Schema::dropIfExists('direcionamento_pontos_venda');
    }
};

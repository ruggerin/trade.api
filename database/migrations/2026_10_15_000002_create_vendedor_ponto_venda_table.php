<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Carteira (docs/73 §11.1): quais vendedores atendem cada loja. N × N de propósito — loja grande
        // pode ter um RCA por linha de produto. Sem empresa_id: os dois lados já são da mesma empresa.
        Schema::create('vendedor_ponto_venda', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('vendedor_id')->constrained('vendedores')->cascadeOnDelete();
            $table->foreignId('ponto_venda_id')->constrained('pontos_venda')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['vendedor_id', 'ponto_venda_id']);
            $table->index('ponto_venda_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendedor_ponto_venda');
    }
};

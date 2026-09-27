<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Lista predefinida de produtos do formulário (granularidade PRODUTO) — quando preenchida,
        // o promotor só vincula o registro a um destes produtos, em qualquer loja (esteja no mix
        // ou não, ex.: pesquisa de preço de concorrente). Vazia = comportamento de antes (mix/
        // campanha da visita).
        Schema::create('tipo_registro_produtos', function (Blueprint $table): void {
            $table->foreignId('tipo_registro_id')->constrained('tipos_registro')->cascadeOnDelete();
            $table->foreignId('produto_auditoria_id')->constrained('produtos_auditoria')->cascadeOnDelete();
            $table->unsignedInteger('ordem')->default(0);
            $table->primary(['tipo_registro_id', 'produto_auditoria_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tipo_registro_produtos');
    }
};

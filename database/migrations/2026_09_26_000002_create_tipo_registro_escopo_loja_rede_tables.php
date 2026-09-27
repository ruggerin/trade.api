<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Ação obrigatória restrita a lojas/redes (escopo_acao = LOJA_REDE, docs/40-ACAO-OBRIGATORIA-
        // LOJA-REDE.md §3.1). Pivôs sem empresa_id próprio — isolamento herdado do TipoRegistro.
        // Loja OU rede basta (OR); as duas vazias = todas as lojas.
        Schema::create('tipo_registro_pontos_venda', function (Blueprint $table): void {
            $table->foreignId('tipo_registro_id')->constrained('tipos_registro')->cascadeOnDelete();
            $table->foreignId('ponto_venda_id')->constrained('pontos_venda')->cascadeOnDelete();
            $table->primary(['tipo_registro_id', 'ponto_venda_id']);
        });

        Schema::create('tipo_registro_redes_lojas', function (Blueprint $table): void {
            $table->foreignId('tipo_registro_id')->constrained('tipos_registro')->cascadeOnDelete();
            $table->foreignId('rede_loja_id')->constrained('redes_lojas')->cascadeOnDelete();
            $table->primary(['tipo_registro_id', 'rede_loja_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tipo_registro_redes_lojas');
        Schema::dropIfExists('tipo_registro_pontos_venda');
    }
};

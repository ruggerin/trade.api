<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pedidos do ERP por loja (docs/28-RELATORIOS-FEEDBACK-HISTORICO.md §4.2). Quem grava é um
 * integrador externo consumindo a API — este sistema não sincroniza com o ERP. Sem valor
 * monetário em lugar nenhum, de propósito ("promotor não precisa saber valor"). Status
 * (pendente/entregue) nunca é persistido: um pedido com pelo menos uma entrega está entregue.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pedidos', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique()->default(DB::raw('gen_random_uuid()'));
            $table->foreignId('empresa_id')->constrained('empresas');
            $table->foreignId('ponto_venda_id')->constrained('pontos_venda');
            $table->string('numero_pedido');
            $table->string('numero_nf')->nullable();
            $table->date('data_pedido');
            $table->text('observacao')->nullable();
            $table->timestamps();

            // Idempotência: o integrador reenvia o mesmo pedido a cada ciclo de sync.
            $table->unique(['empresa_id', 'numero_pedido']);
            $table->index(['ponto_venda_id', 'data_pedido']);
        });

        Schema::create('pedido_itens', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique()->default(DB::raw('gen_random_uuid()'));
            $table->foreignId('pedido_id')->constrained('pedidos')->cascadeOnDelete();
            // Null quando o código do ERP não bate com nenhum produto do catálogo — o item
            // continua existindo, com a descrição crua.
            $table->foreignId('produto_id')->nullable()->constrained('produtos_auditoria')->nullOnDelete();
            $table->string('codigo_externo_produto');
            $table->string('descricao_produto');
            $table->decimal('quantidade', 14, 3);
            $table->timestamps();
        });

        Schema::create('pedido_entregas', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique()->default(DB::raw('gen_random_uuid()'));
            $table->foreignId('pedido_id')->constrained('pedidos')->cascadeOnDelete();
            $table->timestamp('data_entrega');
            $table->text('observacao')->nullable();
            $table->timestamps();

            // Reenvio da mesma entrega no sync não duplica.
            $table->unique(['pedido_id', 'data_entrega']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pedido_entregas');
        Schema::dropIfExists('pedido_itens');
        Schema::dropIfExists('pedidos');
    }
};

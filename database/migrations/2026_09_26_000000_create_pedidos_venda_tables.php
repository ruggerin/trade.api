<?php

use App\Enums\AcaoHistoricoPedidoVenda;
use App\Enums\StatusPedidoVenda;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Preço de venda vive no próprio catálogo na v1 (docs/38-PEDIDO-VENDEDOR.md §6) — um preço
        // só por produto, por empresa. Nullable: produto sem preço configurado não entra em pedido.
        Schema::table('produtos_auditoria', function (Blueprint $table): void {
            $table->decimal('preco_tabela', 12, 2)->nullable();
            $table->decimal('desconto_maximo_pct', 5, 2)->nullable();
        });

        // Pedido de venda digitado pelo vendedor (docs/38) — domínio de ESCRITA, distinto de
        // `pedidos`/`pedido_itens` (somente leitura, espelho do ERP, docs/28 §4.2), que continuam
        // intocados.
        Schema::create('pedidos_venda', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique()->default(DB::raw('gen_random_uuid()'));
            $table->foreignId('empresa_id')->constrained('empresas');
            $table->foreignId('ponto_venda_id')->constrained('pontos_venda');
            $table->foreignId('criado_por_id')->constrained('usuarios');
            // De qual visita nasceu, quando nasce em campo — null = pedido solto (§10 pergunta 4).
            $table->foreignId('visita_id')->nullable()->constrained('visitas');
            $table->enum('status', array_column(StatusPedidoVenda::cases(), 'value'))->default(StatusPedidoVenda::RASCUNHO->value);
            $table->text('observacao')->nullable();
            $table->timestamp('concluido_em')->nullable();
            $table->foreignId('concluido_por_id')->nullable()->constrained('usuarios');
            $table->timestamps();

            $table->index(['empresa_id', 'status']);
            $table->index(['empresa_id', 'criado_por_id']);
        });

        // Sem empresa_id próprio — isolamento herdado de pedido_venda_id (mesmo padrão de
        // plano_acao_etapas).
        Schema::create('pedido_venda_itens', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique()->default(DB::raw('gen_random_uuid()'));
            $table->foreignId('pedido_venda_id')->constrained('pedidos_venda')->cascadeOnDelete();
            $table->foreignId('produto_id')->constrained('produtos_auditoria');
            $table->decimal('quantidade', 12, 3);
            // Snapshot do catálogo no momento da inclusão — o produto pode mudar de preço depois.
            $table->decimal('preco_tabela', 12, 2);
            $table->decimal('desconto_maximo_pct', 5, 2)->nullable();
            // O que o vendedor digitou.
            $table->decimal('preco', 12, 2);
            // preco < preco_tabela × (1 − desconto_maximo_pct/100), calculado ao incluir (§6).
            $table->boolean('requer_autorizacao')->default(false);
            $table->timestamps();
        });

        // Log append-only (mesmo molde de plano_acao_historicos) — nunca editado, só created_at.
        Schema::create('pedido_venda_historicos', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique()->default(DB::raw('gen_random_uuid()'));
            $table->foreignId('pedido_venda_id')->constrained('pedidos_venda')->cascadeOnDelete();
            $table->foreignId('usuario_id')->nullable()->constrained('usuarios');
            $table->enum('acao', array_column(AcaoHistoricoPedidoVenda::cases(), 'value'));
            $table->string('descricao');
            // Obrigatório em REJEITADO (validado no controller).
            $table->text('motivo')->nullable();
            // Itens/preços no momento de uma solicitação/aprovação — auditoria do que valia na hora,
            // mesmo que o catálogo mude depois (§6, equivalente ao OrderApproval do ImportaFácil).
            $table->jsonb('snapshot')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pedido_venda_historicos');
        Schema::dropIfExists('pedido_venda_itens');
        Schema::dropIfExists('pedidos_venda');

        Schema::table('produtos_auditoria', function (Blueprint $table): void {
            $table->dropColumn(['preco_tabela', 'desconto_maximo_pct']);
        });
    }
};

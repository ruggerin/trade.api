<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * docs/54-DETALHE-PEDIDO-PREVISAO-E-NOTIFICACAO.md — previsão de chegada do pedido (vem do ERP,
 * nível do pedido inteiro) e o aviso ao promotor da loja: "pedido a caminho" quando a previsão
 * aparece ou muda, e "pedido entregue" quando a entrega é confirmada. Fonte própria, separada dos
 * comentários — o sino do app junta as duas na tela (§3).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pedidos', function (Blueprint $table) {
            $table->date('data_previsao_entrega')->nullable();
        });

        Schema::create('notificacoes_pedido', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->default(DB::raw('gen_random_uuid()'))->unique();
            $table->foreignId('usuario_id')->constrained('usuarios')->cascadeOnDelete();
            $table->foreignId('pedido_id')->constrained('pedidos')->cascadeOnDelete();
            // PREVISTO (a caminho, com previsão) | ENTREGUE.
            $table->string('tipo', 20);
            // Previsão no momento do aviso — "nova previsão: 05/10" quando o ERP revisa a data.
            $table->date('data_previsao')->nullable();
            $table->timestamp('lida_em')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['usuario_id', 'lida_em']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notificacoes_pedido');

        Schema::table('pedidos', function (Blueprint $table) {
            $table->dropColumn('data_previsao_entrega');
        });
    }
};

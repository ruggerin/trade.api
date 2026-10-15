<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Valor e faturamento no pedido do ERP (docs/61 §5.1). Tudo nullable: pedido sem valor fica
 * "sem informação", nunca zero. Devolução entra com o sinal que o ERP mandou (Winthor emite a
 * devolução com valor NEGATIVO), então a soma já dá o líquido.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pedidos', function (Blueprint $table): void {
            $table->decimal('valor_total', 14, 2)->nullable()->after('observacao');
            $table->decimal('valor_lucro_total', 14, 2)->nullable()->after('valor_total');
            // NAO_FATURADO | FATURADO | CANCELADO | DEVOLVIDO — distinto do status de ENTREGA.
            $table->string('status_faturamento', 20)->nullable()->after('valor_lucro_total');
            $table->date('faturado_em')->nullable()->after('status_faturamento');
            // O ERP mandou total/itens que não batem — sinaliza em vez de escolher em silêncio.
            $table->boolean('valor_divergente')->default(false)->after('faturado_em');

            $table->index(['empresa_id', 'faturado_em']);
            $table->index(['ponto_venda_id', 'faturado_em']);
        });

        Schema::table('pedido_itens', function (Blueprint $table): void {
            $table->decimal('preco_unitario', 14, 4)->nullable()->after('quantidade');
            $table->decimal('subtotal', 14, 2)->nullable()->after('preco_unitario');
            $table->decimal('custo_unitario', 14, 4)->nullable()->after('subtotal');
            $table->decimal('valor_lucro', 14, 2)->nullable()->after('custo_unitario');
            // ERP = veio do ERP; CALCULADO = completado pelo sistema (custo ou lucro derivado).
            $table->string('origem_lucro', 12)->nullable()->after('valor_lucro');
            $table->boolean('valor_divergente')->default(false)->after('origem_lucro');
        });
    }

    public function down(): void
    {
        Schema::table('pedido_itens', function (Blueprint $table): void {
            $table->dropColumn(['preco_unitario', 'subtotal', 'custo_unitario', 'valor_lucro', 'origem_lucro', 'valor_divergente']);
        });

        Schema::table('pedidos', function (Blueprint $table): void {
            $table->dropIndex(['empresa_id', 'faturado_em']);
            $table->dropIndex(['ponto_venda_id', 'faturado_em']);
            $table->dropColumn(['valor_total', 'valor_lucro_total', 'status_faturamento', 'faturado_em', 'valor_divergente']);
        });
    }
};

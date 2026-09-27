<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Item de um PedidoVenda — `preco_tabela`/`desconto_maximo_pct` são snapshot do catálogo no
 * momento da inclusão. Subtotal nunca é gravado, só calculado na leitura. Ver
 * docs/38-PEDIDO-VENDEDOR.md §6.
 */
class PedidoVendaItem extends Model
{
    use HasUuid;

    protected $table = 'pedido_venda_itens';

    protected $fillable = [
        'pedido_venda_id',
        'produto_id',
        'quantidade',
        'preco_tabela',
        'desconto_maximo_pct',
        'preco',
        'requer_autorizacao',
    ];

    protected function casts(): array
    {
        return [
            'quantidade' => 'decimal:3',
            'preco_tabela' => 'decimal:2',
            'desconto_maximo_pct' => 'decimal:2',
            'preco' => 'decimal:2',
            'requer_autorizacao' => 'boolean',
        ];
    }

    /** preco_tabela × (1 − desconto_maximo_pct/100); desconto nulo = 0 (§6). */
    public static function precoMinimo(float $precoTabela, ?float $descontoMaximoPct): float
    {
        return round($precoTabela * (1 - ($descontoMaximoPct ?? 0) / 100), 2);
    }

    public function precoMinimoCalculado(): float
    {
        return self::precoMinimo((float) $this->preco_tabela, $this->desconto_maximo_pct !== null ? (float) $this->desconto_maximo_pct : null);
    }

    public function subtotal(): float
    {
        return round((float) $this->quantidade * (float) $this->preco, 2);
    }

    public function pedidoVenda(): BelongsTo
    {
        return $this->belongsTo(PedidoVenda::class);
    }

    public function produto(): BelongsTo
    {
        return $this->belongsTo(ProdutoAuditoria::class, 'produto_id');
    }
}

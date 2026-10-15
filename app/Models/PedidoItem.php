<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Sem BelongsToEmpresa — herda o isolamento do Pedido pai, como VisitaRegistro herda de Visita. */
class PedidoItem extends Model
{
    use HasUuid;

    protected $table = 'pedido_itens';

    protected $fillable = [
        'pedido_id', 'produto_id', 'codigo_externo_produto', 'descricao_produto', 'quantidade',
        'preco_unitario', 'subtotal', 'custo_unitario', 'valor_lucro', 'origem_lucro', 'valor_divergente',
    ];

    protected function casts(): array
    {
        return [
            'quantidade' => 'float',
            'preco_unitario' => 'float',
            'subtotal' => 'float',
            'custo_unitario' => 'float',
            'valor_lucro' => 'float',
            'valor_divergente' => 'boolean',
        ];
    }

    public function produto(): BelongsTo
    {
        return $this->belongsTo(ProdutoAuditoria::class, 'produto_id');
    }
}

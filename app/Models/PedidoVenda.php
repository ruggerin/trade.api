<?php

namespace App\Models;

use App\Enums\StatusPedidoVenda;
use App\Models\Concerns\BelongsToEmpresa;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Pedido de venda digitado pelo vendedor (promotor em "modo Vendedor") — escrita, com preço.
 * Não confundir com App\Models\Pedido (somente leitura, espelho do ERP). Ver
 * App\Http\Controllers\PedidoVendaController e docs/38-PEDIDO-VENDEDOR.md.
 */
class PedidoVenda extends Model
{
    use BelongsToEmpresa, HasUuid;

    protected $table = 'pedidos_venda';

    protected $fillable = [
        'empresa_id',
        'ponto_venda_id',
        'criado_por_id',
        'visita_id',
        'status',
        'observacao',
        'concluido_em',
        'concluido_por_id',
    ];

    protected function casts(): array
    {
        return [
            'status' => StatusPedidoVenda::class,
            'concluido_em' => 'datetime',
        ];
    }

    public function pontoVenda(): BelongsTo
    {
        return $this->belongsTo(PontoVenda::class);
    }

    public function visita(): BelongsTo
    {
        return $this->belongsTo(Visita::class);
    }

    public function itens(): HasMany
    {
        return $this->hasMany(PedidoVendaItem::class)->orderBy('id');
    }

    public function historicos(): HasMany
    {
        return $this->hasMany(PedidoVendaHistorico::class)->orderByDesc('created_at')->orderByDesc('id');
    }

    public function criadoPor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'criado_por_id');
    }

    public function concluidoPor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'concluido_por_id');
    }
}

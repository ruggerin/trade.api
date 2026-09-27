<?php

namespace App\Models;

use App\Enums\AcaoHistoricoPedidoVenda;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Log append-only de um PedidoVenda — mesmo molde de PlanoAcaoHistorico. `snapshot` guarda os
 * itens/preços no momento de uma solicitação/aprovação. Sem `updated_at`: nunca editado. Ver
 * docs/38-PEDIDO-VENDEDOR.md §6.
 */
class PedidoVendaHistorico extends Model
{
    use HasUuid;

    protected $table = 'pedido_venda_historicos';

    public const UPDATED_AT = null;

    protected $fillable = [
        'pedido_venda_id',
        'usuario_id',
        'acao',
        'descricao',
        'motivo',
        'snapshot',
    ];

    protected function casts(): array
    {
        return [
            'acao' => AcaoHistoricoPedidoVenda::class,
            'snapshot' => 'array',
        ];
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class);
    }
}

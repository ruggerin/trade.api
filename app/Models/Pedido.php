<?php

namespace App\Models;

use App\Models\Concerns\BelongsToEmpresa;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Pedido vindo do ERP, gravado por um integrador externo — ver
 * docs/28-RELATORIOS-FEEDBACK-HISTORICO.md §4.2. Só consulta pro promotor.
 */
class Pedido extends Model
{
    use BelongsToEmpresa, HasFactory, HasUuid;

    protected $table = 'pedidos';

    // data_previsao_entrega: previsão de chegada na loja mandada pelo ERP (docs/54 §4).
    protected $fillable = ['empresa_id', 'ponto_venda_id', 'numero_pedido', 'numero_nf', 'data_pedido', 'data_previsao_entrega', 'observacao'];

    protected function casts(): array
    {
        return ['data_pedido' => 'date', 'data_previsao_entrega' => 'date'];
    }

    public function pontoVenda(): BelongsTo
    {
        return $this->belongsTo(PontoVenda::class, 'ponto_venda_id');
    }

    public function itens(): HasMany
    {
        return $this->hasMany(PedidoItem::class)->orderBy('id');
    }

    public function entregas(): HasMany
    {
        return $this->hasMany(PedidoEntrega::class)->orderBy('data_entrega');
    }
}

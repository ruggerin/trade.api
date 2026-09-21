<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;

/** Sem BelongsToEmpresa — herda o isolamento do Pedido pai. */
class PedidoEntrega extends Model
{
    use HasUuid;

    protected $table = 'pedido_entregas';

    protected $fillable = ['pedido_id', 'data_entrega', 'observacao'];

    protected function casts(): array
    {
        return ['data_entrega' => 'datetime'];
    }
}

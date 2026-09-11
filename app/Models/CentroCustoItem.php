<?php

namespace App\Models;

use App\Enums\CategoriaCentroCustoItem;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Linha de custo dentro de um CentroCusto (ex.: "Salário", "Vale-alimentação", "Sistema") —
 * lista livre, não colunas fixas. Ver docs/08-CENTRO-DE-CUSTO.md.
 */
class CentroCustoItem extends Model
{
    use HasUuid;

    protected $table = 'centro_custo_itens';

    protected $fillable = [
        'centro_custo_id',
        'categoria',
        'descricao',
        'valor_mensal',
        'ordem',
    ];

    protected function casts(): array
    {
        return [
            'categoria' => CategoriaCentroCustoItem::class,
            'valor_mensal' => 'decimal:2',
        ];
    }

    public function centroCusto(): BelongsTo
    {
        return $this->belongsTo(CentroCusto::class, 'centro_custo_id');
    }
}

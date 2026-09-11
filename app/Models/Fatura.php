<?php

namespace App\Models;

use App\Enums\StatusFatura;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Registro manual de cobrança por empresa — sem gateway de pagamento, sem geração de
 * boleto/nota (decisão de escopo, ver docs/00-VISAO-GERAL.md). Sem BelongsToEmpresa: é dado
 * interno do SUPERADMIN, acesso controlado só pelo middleware user_type:SUPERADMIN, mesmo
 * padrão do próprio Model Empresa.
 */
class Fatura extends Model
{
    use HasFactory, HasUuid;

    protected $table = 'faturas';

    protected $fillable = [
        'empresa_id',
        'valor',
        'referencia',
        'vencimento',
        'status',
        'pago_em',
        'observacao',
    ];

    protected function casts(): array
    {
        return [
            'valor' => 'decimal:2',
            'referencia' => 'date',
            'vencimento' => 'date',
            'status' => StatusFatura::class,
            'pago_em' => 'date',
        ];
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Uma chamada de uma integração à API (docs/96 §4.3) — gravada pelo EnsureIntegracao depois da
 * resposta. Não é tenant-aware por conta própria: sempre lida a partir da Integracao, que é.
 */
class IntegracaoRequisicao extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'integracao_requisicoes';

    protected $fillable = [
        'integracao_id',
        'metodo',
        'rota',
        'status',
        'ip',
        'duracao_ms',
        'registros',
        'erro',
    ];

    protected function casts(): array
    {
        return [
            'status' => 'integer',
            'duracao_ms' => 'integer',
            'registros' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function integracao(): BelongsTo
    {
        return $this->belongsTo(Integracao::class);
    }
}

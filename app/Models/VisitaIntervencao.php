<?php

namespace App\Models;

use App\Enums\AcaoIntervencaoVisita;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Log append-only de uma intervenção administrativa numa Visita (cancelamento, checkout forçado
 * ou correção de horário) — nunca editado. Sem `empresa_id` próprio, isolamento herdado de
 * `visita_id` (mesmo padrão de App\Models\ContratoHistorico). Ver
 * App\Http\Controllers\VisitaController e docs/15-INTERVENCAO-ADMINISTRATIVA-VISITA.md.
 */
class VisitaIntervencao extends Model
{
    use HasUuid;

    // O pluralizador do Laravel erra o plural em português ("visita_intervencaos").
    protected $table = 'visita_intervencoes';

    protected $fillable = [
        'visita_id',
        'usuario_id',
        'acao',
        'motivo',
        'descricao',
        'valores_anteriores',
        'valores_novos',
    ];

    protected function casts(): array
    {
        return [
            'acao' => AcaoIntervencaoVisita::class,
            'valores_anteriores' => 'array',
            'valores_novos' => 'array',
        ];
    }

    public function visita(): BelongsTo
    {
        return $this->belongsTo(Visita::class);
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class);
    }
}

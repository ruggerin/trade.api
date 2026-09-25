<?php

namespace App\Models;

use App\Enums\AcaoHistoricoPlanoAcao;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Log append-only de um PlanoAcao — cada criação, etapa adicionada, mudança de status de etapa,
 * conclusão e cancelamento vira uma linha, nunca um UPDATE que apaga o estado anterior. Sem
 * `updated_at`: nunca editado. Ver docs/37-PLANOS-DE-ACAO.md §4.9.
 */
class PlanoAcaoHistorico extends Model
{
    use HasUuid;

    protected $table = 'plano_acao_historicos';

    public const UPDATED_AT = null;

    protected $fillable = [
        'plano_acao_id',
        'etapa_id',
        'usuario_id',
        'acao',
        'status_anterior',
        'status_novo',
        'motivo',
        'descricao',
    ];

    protected function casts(): array
    {
        return [
            'acao' => AcaoHistoricoPlanoAcao::class,
        ];
    }

    public function etapa(): BelongsTo
    {
        return $this->belongsTo(PlanoAcaoEtapa::class, 'etapa_id');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class);
    }
}

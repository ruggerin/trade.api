<?php

namespace App\Models;

use App\Enums\StatusEtapaPlanoAcao;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Etapa de um PlanoAcao — prazo, responsável (do sistema e/ou externo) e evidência próprios.
 * Sem `empresa_id` próprio, isolamento herdado de `plano_acao_id` (mesmo padrão de
 * VisitaIntervencao). Ver docs/37-PLANOS-DE-ACAO.md §4.
 */
class PlanoAcaoEtapa extends Model
{
    use HasUuid;

    protected $table = 'plano_acao_etapas';

    protected $fillable = [
        'plano_acao_id',
        'ordem',
        'titulo',
        'descricao',
        'prazo',
        'status',
        'responsavel_id',
        'responsavel_externo_nome',
        'responsavel_externo_contato',
        'evidencia_obrigatoria',
        'evidencia_texto',
        'evidencia_arquivo_path',
        'motivo',
        'feita_em',
        'feita_por_id',
    ];

    protected function casts(): array
    {
        return [
            'status' => StatusEtapaPlanoAcao::class,
            'prazo' => 'date',
            'evidencia_obrigatoria' => 'boolean',
            'auto_concluir_ao_finalizar_artefato' => 'boolean',
            'feita_em' => 'datetime',
        ];
    }

    /**
     * Calculado, nunca gravado (§4.5) — prazo é data sem hora, então vence no fim do dia.
     */
    public function atrasada(): bool
    {
        return $this->prazo !== null
            && ! $this->status->finalizada()
            && $this->prazo->copy()->endOfDay()->isPast();
    }

    public function planoAcao(): BelongsTo
    {
        return $this->belongsTo(PlanoAcao::class);
    }

    public function responsavel(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'responsavel_id');
    }

    public function feitaPor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'feita_por_id');
    }
}

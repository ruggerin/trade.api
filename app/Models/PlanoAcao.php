<?php

namespace App\Models;

use App\Enums\StatusPlanoAcao;
use App\Models\Concerns\BelongsToEmpresa;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Rastreamento de resolução multi-etapa de um problema operacional — um alerta de campo que
 * exige várias etapas, por pessoas diferentes, ao longo de dias, em vez do "Resolver" boolean.
 * Ver App\Http\Controllers\PlanoAcaoController e docs/37-PLANOS-DE-ACAO.md.
 */
class PlanoAcao extends Model
{
    use BelongsToEmpresa, HasUuid;

    // O pluralizador do Laravel erra o plural em português ("plano_acaos").
    protected $table = 'planos_acao';

    public const ORIGEM_ALERTA = 'ALERTA';

    public const ORIGEM_LIVRE = 'LIVRE';

    protected $fillable = [
        'empresa_id',
        'titulo',
        'descricao',
        'origem_tipo',
        'origem_registro_id',
        'ponto_venda_id',
        'rede_loja_id',
        'status',
        'prazo',
        'criado_por_id',
        'concluido_em',
        'concluido_por_id',
        'cancelado_em',
        'cancelado_por_id',
        'motivo_cancelamento',
    ];

    protected function casts(): array
    {
        return [
            'status' => StatusPlanoAcao::class,
            'prazo' => 'date',
            'concluido_em' => 'datetime',
            'cancelado_em' => 'datetime',
        ];
    }

    public function origemRegistro(): BelongsTo
    {
        return $this->belongsTo(VisitaRegistro::class, 'origem_registro_id');
    }

    /**
     * Escopo opcional — loja OU rede, nunca as duas (CHECK no banco). Plano de alerta herda a
     * loja do alerta; plano livre escolhe (ou fica sem nenhum).
     */
    public function pontoVenda(): BelongsTo
    {
        return $this->belongsTo(PontoVenda::class);
    }

    public function redeLoja(): BelongsTo
    {
        return $this->belongsTo(RedeLoja::class);
    }

    public function etapas(): HasMany
    {
        return $this->hasMany(PlanoAcaoEtapa::class)->orderBy('ordem');
    }

    public function historicos(): HasMany
    {
        return $this->hasMany(PlanoAcaoHistorico::class)->orderByDesc('created_at')->orderByDesc('id');
    }

    public function criadoPor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'criado_por_id');
    }

    public function concluidoPor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'concluido_por_id');
    }

    public function canceladoPor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'cancelado_por_id');
    }
}

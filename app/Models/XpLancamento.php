<?php

namespace App\Models;

use App\Models\Concerns\BelongsToEmpresa;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Lançamento da régua de XP (docs/88 §14), append-only: pontos nunca mudam. Recalcular é criar
 * lançamento novo (AJUSTE/ESTORNO), nunca editar. A única transição permitida é o status de um
 * PENDENTE (aguardando avaliação do gestor) virar OFICIAL ou DESCARTADO.
 */
class XpLancamento extends Model
{
    use BelongsToEmpresa, HasUuid;

    public const PLANO = 'PLANO';

    public const INICIATIVA = 'INICIATIVA';

    public const PERDA = 'PERDA';

    public const ESTORNO = 'ESTORNO';

    public const AJUSTE = 'AJUSTE';

    public const OFICIAL = 'OFICIAL';

    public const PENDENTE = 'PENDENTE';

    public const DESCARTADO = 'DESCARTADO';

    protected $table = 'xp_lancamentos';

    public $timestamps = false;

    protected $fillable = [
        'empresa_id', 'usuario_id', 'ordem_servico_id', 'visita_id', 'visita_registro_id',
        'natureza', 'categoria', 'pontos', 'status', 'competencia', 'motivo', 'criado_por_id', 'resolvido_em',
    ];

    protected function casts(): array
    {
        return [
            'pontos' => 'integer',
            'competencia' => 'date',
            'created_at' => 'datetime',
            'resolvido_em' => 'datetime',
        ];
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class);
    }

    public function ordemServico(): BelongsTo
    {
        return $this->belongsTo(OrdemServico::class);
    }

    public function visita(): BelongsTo
    {
        return $this->belongsTo(Visita::class);
    }

    public function registro(): BelongsTo
    {
        return $this->belongsTo(VisitaRegistro::class, 'visita_registro_id');
    }

    public function criadoPor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'criado_por_id');
    }

    public function classificacoes(): HasMany
    {
        return $this->hasMany(XpOcorrenciaClassificacao::class)->orderBy('id');
    }

    public function classificacaoAtual(): HasOne
    {
        return $this->hasOne(XpOcorrenciaClassificacao::class)->latestOfMany();
    }
}

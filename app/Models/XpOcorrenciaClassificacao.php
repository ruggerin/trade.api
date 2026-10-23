<?php

namespace App\Models;

use App\Models\Concerns\BelongsToEmpresa;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Como o gestor tratou uma perda da régua (docs/88 §8): justificada ou não, a causa, um
 * comentário e o plano de ação gerado. Append-only — reclassificar cria linha nova. O número
 * nunca é perdoado: a classificação não muda pontos.
 */
class XpOcorrenciaClassificacao extends Model
{
    use BelongsToEmpresa, HasUuid;

    protected $table = 'xp_ocorrencia_classificacoes';

    public $timestamps = false;

    protected $fillable = [
        'empresa_id', 'xp_lancamento_id', 'justificada', 'motivo_nao_execucao_id', 'comentario', 'plano_acao_id', 'criado_por_id',
    ];

    protected function casts(): array
    {
        return ['justificada' => 'boolean', 'created_at' => 'datetime'];
    }

    public function lancamento(): BelongsTo
    {
        return $this->belongsTo(XpLancamento::class, 'xp_lancamento_id');
    }

    public function motivo(): BelongsTo
    {
        return $this->belongsTo(MotivoNaoExecucao::class, 'motivo_nao_execucao_id');
    }

    public function planoAcao(): BelongsTo
    {
        return $this->belongsTo(PlanoAcao::class);
    }

    public function criadoPor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'criado_por_id');
    }
}

<?php

namespace App\Models;

use App\Models\Concerns\BelongsToEmpresa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Uma linha por promotor e dia (docs/88 §14) — o que equipe e empresa somam. Recalculada a partir
 * dos lançamentos e das OS do dia (App\Support\Xp\ResumoXp), nunca editada à mão.
 */
class XpResumoDiario extends Model
{
    use BelongsToEmpresa;

    protected $table = 'xp_resumos_diarios';

    public $timestamps = false;

    protected $fillable = [
        'empresa_id', 'usuario_id', 'dia', 'possivel', 'realizado', 'iniciativa', 'pendente', 'perdas',
        'visitas_previstas', 'visitas_feitas', 'visitas_completas', 'visitas_protegidas', 'atualizado_em',
    ];

    protected function casts(): array
    {
        return [
            'dia' => 'date',
            'possivel' => 'integer',
            'realizado' => 'integer',
            'iniciativa' => 'integer',
            'pendente' => 'integer',
            'perdas' => 'array',
            'visitas_previstas' => 'integer',
            'visitas_feitas' => 'integer',
            'visitas_completas' => 'integer',
            'visitas_protegidas' => 'integer',
            'atualizado_em' => 'datetime',
        ];
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class);
    }
}

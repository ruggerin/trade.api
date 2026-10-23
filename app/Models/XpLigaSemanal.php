<?php

namespace App\Models;

use App\Models\Concerns\BelongsToEmpresa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Classificação de uma semana da liga já fechada (docs/88 §12.4). Gravada na virada (segunda,
 * 00h no fuso da empresa) e nunca mais muda, mesmo que um XP seja aprovado depois.
 */
class XpLigaSemanal extends Model
{
    use BelongsToEmpresa;

    protected $table = 'xp_ligas_semanais';

    public $timestamps = false;

    protected $fillable = [
        'empresa_id', 'supervisor_id', 'semana_inicio', 'usuario_id', 'posicao', 'xp', 'plano', 'iniciativa',
        'possivel', 'visitas_completas', 'fechado_em',
    ];

    protected function casts(): array
    {
        return [
            'semana_inicio' => 'date',
            'fechado_em' => 'datetime',
            'posicao' => 'integer',
            'xp' => 'integer',
            'plano' => 'integer',
            'iniciativa' => 'integer',
            'possivel' => 'integer',
            'visitas_completas' => 'integer',
        ];
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class);
    }
}

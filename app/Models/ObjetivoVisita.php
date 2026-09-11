<?php

namespace App\Models;

use App\Models\Concerns\BelongsToEmpresa;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Motivo de negócio de uma OrdemServico (ex. "Reposição", "Negociação") — cadastro por empresa,
 * mesmo desenho de TipoVisita mas sem cor: tipo_visita é a classificação visual/operacional já
 * usada em toda a OS, objetivo_visita é o motivo específico daquele compromisso, eixo diferente.
 * Ver docs/13-AGENDA-MOBILE-E-AUTONOMIA.md.
 */
class ObjetivoVisita extends Model
{
    use BelongsToEmpresa, HasFactory, HasUuid;

    protected $table = 'objetivos_visita';

    protected $fillable = [
        'empresa_id',
        'descricao',
        'ativo',
    ];

    protected function casts(): array
    {
        return [
            'ativo' => 'boolean',
        ];
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }
}

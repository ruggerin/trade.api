<?php

namespace App\Models;

use App\Models\Concerns\BelongsToEmpresa;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tag colorida pra classificar uma OrdemServico (manual ou gerada por AgendaVisita) — ex.
 * "Reposição" vermelho, "Auditoria" azul. Ver docs/10-AGENDA-VISITA.md.
 */
class TipoVisita extends Model
{
    use BelongsToEmpresa, HasFactory, HasUuid;

    protected $table = 'tipos_visita';

    protected $fillable = [
        'empresa_id',
        'descricao',
        'cor',
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

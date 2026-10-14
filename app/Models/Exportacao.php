<?php

namespace App\Models;

use App\Models\Concerns\BelongsToEmpresa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Linha do log de exportações (docs/68 §4.4) — append-only. */
class Exportacao extends Model
{
    use BelongsToEmpresa;

    protected $table = 'exportacoes';

    public const UPDATED_AT = null;

    protected $fillable = ['empresa_id', 'usuario_id', 'rotina', 'formato', 'filtros', 'linhas'];

    protected function casts(): array
    {
        return ['filtros' => 'array', 'created_at' => 'datetime'];
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class);
    }
}

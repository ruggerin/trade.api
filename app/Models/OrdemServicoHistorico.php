<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Log append-only de OrdemServico (docs/59) — sem `updated_at`, nunca editado. Isolamento por
 * empresa herdado de OrdemServico via ordem_servico_id.
 */
class OrdemServicoHistorico extends Model
{
    use HasUuid;

    public const UPDATED_AT = null;

    protected $table = 'ordem_servico_historicos';

    protected $fillable = ['ordem_servico_id', 'usuario_id', 'acao', 'dados'];

    protected function casts(): array
    {
        return ['dados' => 'array'];
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class);
    }
}

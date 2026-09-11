<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Log de alterações do contrato (append-only, nunca editado) — ver
 * App\Http\Controllers\ContratoController::registrarHistorico e docs/02-API-BACKEND.md.
 */
class ContratoHistorico extends Model
{
    use HasUuid;

    protected $fillable = [
        'contrato_id',
        'usuario_id',
        'descricao',
    ];

    public function contrato(): BelongsTo
    {
        return $this->belongsTo(Contrato::class);
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class);
    }
}

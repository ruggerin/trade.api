<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Log de login append-only — cada linha é um login bem-sucedido, nunca atualizada. Diferente de
 * Dispositivo, que guarda só o último acesso (sobrescrito a cada login). Ver
 * AuthController::login e UsuarioController::historico.
 */
class UsuarioLoginLog extends Model
{
    protected $fillable = [
        'usuario_id',
        'dispositivo_identificador',
        'dispositivo_nome',
    ];

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class);
    }
}

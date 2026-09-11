<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 1:1 com Usuario — registra o aparelho da sessão ativa de um PROMOTOR (trava de 1
 * dispositivo por vez, ver AuthController::login). Sem uuid/empresa_id próprios, sem
 * endpoint dedicado.
 */
class Dispositivo extends Model
{
    protected $fillable = ['usuario_id', 'identificador', 'nome', 'ultimo_acesso_em'];

    protected function casts(): array
    {
        return ['ultimo_acesso_em' => 'datetime'];
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class);
    }
}

<?php

namespace App\Models;

use Laravel\Sanctum\PersonalAccessToken as SanctumPersonalAccessToken;

/**
 * Token Sanctum com os campos da docs/67 (canal, IP, user agent, rotação) — registrado em
 * AppServiceProvider via Sanctum::usePersonalAccessTokenModel. Ver App\Support\SessaoApp.
 */
class PersonalAccessToken extends SanctumPersonalAccessToken
{
    protected function casts(): array
    {
        // Somado aos $casts do Sanctum (abilities, last_used_at, expires_at).
        return [
            'rotacionado_em' => 'datetime',
            'revogado_em' => 'datetime',
            'revogado_detalhe' => 'array',
        ];
    }
}

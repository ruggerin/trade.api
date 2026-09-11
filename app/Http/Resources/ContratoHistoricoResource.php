<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\ContratoHistorico
 */
class ContratoHistoricoResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'descricao' => $this->descricao,
            'usuario' => $this->whenLoaded(
                'usuario',
                fn () => $this->usuario ? ['id' => $this->usuario->uuid, 'nome' => $this->usuario->nome] : null,
            ),
            'created_at' => $this->created_at,
        ];
    }
}

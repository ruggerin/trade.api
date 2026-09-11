<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\VisitaIntervencao
 */
class VisitaIntervencaoResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'acao' => $this->acao,
            'motivo' => $this->motivo,
            'descricao' => $this->descricao,
            'valores_anteriores' => $this->valores_anteriores,
            'valores_novos' => $this->valores_novos,
            'usuario' => $this->whenLoaded(
                'usuario',
                fn () => $this->usuario ? ['id' => $this->usuario->uuid, 'nome' => $this->usuario->nome] : null,
            ),
            'created_at' => $this->created_at,
        ];
    }
}

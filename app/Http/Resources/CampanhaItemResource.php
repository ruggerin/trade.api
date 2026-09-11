<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\CampanhaItem
 */
class CampanhaItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'tipo_item' => $this->tipo_item,
            'produto' => $this->whenLoaded('produto', fn () => $this->produto ? ['id' => $this->produto->uuid, 'descricao' => $this->produto->descricao] : null),
            'departamento' => $this->whenLoaded('departamento', fn () => $this->departamento ? ['id' => $this->departamento->uuid, 'descricao' => $this->departamento->descricao] : null),
            'secao' => $this->whenLoaded('secao', fn () => $this->secao ? ['id' => $this->secao->uuid, 'descricao' => $this->secao->descricao] : null),
            'marca' => $this->whenLoaded('marca', fn () => $this->marca ? ['id' => $this->marca->uuid, 'descricao' => $this->marca->descricao] : null),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}

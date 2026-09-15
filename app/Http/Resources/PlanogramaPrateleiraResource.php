<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\PlanogramaPrateleira
 */
class PlanogramaPrateleiraResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'ordem' => $this->ordem,
            'descricao' => $this->descricao,
            'quantidade_blocos' => $this->quantidade_blocos,
            'blocos' => PlanogramaBlocoResource::collection($this->whenLoaded('blocos')),
        ];
    }
}

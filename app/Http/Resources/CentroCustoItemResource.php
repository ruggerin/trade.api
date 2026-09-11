<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\CentroCustoItem
 */
class CentroCustoItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'categoria' => $this->categoria,
            'descricao' => $this->descricao,
            'valor_mensal' => $this->valor_mensal,
            'ordem' => $this->ordem,
        ];
    }
}

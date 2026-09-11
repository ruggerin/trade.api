<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\CentroCusto
 */
class CentroCustoResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'descricao' => $this->descricao,
            'carga_horaria_semanal' => $this->carga_horaria_semanal,
            'itens' => CentroCustoItemResource::collection($this->whenLoaded('itens')),
            // Nunca persistido — sempre computado a partir dos itens + quantidade de
            // promotores vinculados agora. Ver App\Models\CentroCusto::resumoCusto e
            // docs/08-CENTRO-DE-CUSTO.md §4.
            'resumo' => $this->resumoCusto(),
            'ativo' => $this->ativo,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}

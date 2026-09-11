<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\CampanhaAuditoria
 */
class CampanhaAuditoriaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'descricao' => $this->descricao,
            'observacao' => $this->observacao,
            'layout' => $this->layout,
            'vigencia_inicio' => $this->vigencia_inicio,
            'vigencia_fim' => $this->vigencia_fim,
            'restricao' => $this->restricao,
            'exclusividade' => $this->exclusividade,
            'frequencia_dias' => $this->frequencia_dias,
            'execucao_recorrente' => $this->execucao_recorrente,
            'possui_restricao' => $this->possui_restricao,
            'possui_exclusividade' => $this->possui_exclusividade,
            'ativo' => $this->ativo,
            'itens' => CampanhaItemResource::collection($this->whenLoaded('itens')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}

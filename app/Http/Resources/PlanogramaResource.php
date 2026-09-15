<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Planograma
 */
class PlanogramaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'descricao' => $this->descricao,
            'foto_capa_url' => $this->foto_capa_path ? url("/api/planogramas/{$this->uuid}/foto-capa") : null,
            'ativo' => $this->ativo,
            'prateleiras' => PlanogramaPrateleiraResource::collection($this->whenLoaded('prateleiras')),
            // Só vem preenchido pra quem pede como SUPERADMIN — mesmo padrão de
            // TipoRegistroResource/ParametroResource.
            'empresa' => $this->whenLoaded('empresa', fn () => $this->empresa ? new EmpresaResource($this->empresa) : null),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}

<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\DepartamentoAuditoria
 */
class DepartamentoAuditoriaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'descricao' => $this->descricao,
            // Só carregado quando quem pede é SUPERADMIN (filtro/coluna de empresa no admin web,
            // ver App\Http\Controllers\DepartamentoAuditoriaController::index).
            'empresa' => $this->whenLoaded('empresa', fn () => new EmpresaResource($this->empresa)),
            'ativo' => $this->ativo,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}

<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\SecaoAuditoria
 */
class SecaoAuditoriaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'descricao' => $this->descricao,
            'departamento' => $this->whenLoaded(
                'departamento',
                fn () => $this->departamento ? ['id' => $this->departamento->uuid, 'descricao' => $this->departamento->descricao] : null,
            ),
            // Só carregado quando quem pede é SUPERADMIN (filtro/coluna de empresa no admin web).
            'empresa' => $this->whenLoaded('empresa', fn () => new EmpresaResource($this->empresa)),
            'ativo' => $this->ativo,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}

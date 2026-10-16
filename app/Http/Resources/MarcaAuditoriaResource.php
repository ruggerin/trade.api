<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\MarcaAuditoria
 */
class MarcaAuditoriaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'descricao' => $this->descricao,
            'propriedade' => $this->propriedade,
            // Só carregado quando quem pede é SUPERADMIN (filtro/coluna de empresa no admin web).
            'empresa' => $this->whenLoaded('empresa', fn () => new EmpresaResource($this->empresa)),
            'ativo' => $this->ativo,
            // Cadastrada pelo promotor e ainda não aprovada (docs/85 §4.2).
            'a_aprovar' => $this->status_aprovacao === \App\Enums\StatusAprovacao::PENDENTE,
            'criado_por' => $this->whenLoaded('criadoPor', fn () => $this->criadoPor ? ['id' => $this->criadoPor->uuid, 'nome' => $this->criadoPor->nome] : null),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}

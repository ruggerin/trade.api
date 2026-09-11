<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Empresa
 */
class EmpresaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            // Convenção da API: "id" é sempre o uuid, o bigint interno nunca é exposto — ver
            // docs/02-API-BACKEND.md#convenção-de-identificadores-na-api.
            'id' => $this->uuid,
            'razao_social' => $this->razao_social,
            'nome_fantasia' => $this->nome_fantasia,
            'cnpj' => $this->cnpj,
            'plano' => $this->plano,
            'limite_usuarios' => $this->limite_usuarios,
            'limite_pontos_venda' => $this->limite_pontos_venda,
            'limite_licencas' => $this->limite_licencas,
            'ativo' => $this->ativo,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}

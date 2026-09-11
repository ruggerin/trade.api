<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Contrato
 */
class ContratoResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'ponto_venda' => $this->whenLoaded(
                'pontoVenda',
                fn () => ['id' => $this->pontoVenda->uuid, 'fantasia' => $this->pontoVenda->fantasia],
            ),
            'tipo' => $this->tipo,
            'descricao' => $this->descricao,
            'vigencia_inicio' => $this->vigencia_inicio,
            'vigencia_fim' => $this->vigencia_fim,
            'arquivo_url' => $this->arquivo_path ? url("/api/contratos/{$this->uuid}/arquivo") : null,
            // Eager-load opcional (?with_metas=1 em GET /api/contratos) — ver
            // App\Http\Controllers\ContratoController::index e docs/09-CONTRATO-METAS.md §5.
            'metas' => ContratoMetaResource::collection($this->whenLoaded('metas')),
            // SUPERADMIN não pertence a nenhuma empresa — precisa desse campo pra dar contexto
            // de qual tenant é cada contrato na listagem cross-empresa, mesmo padrão de
            // UsuarioResource/DepartamentoAuditoriaResource.
            'empresa' => $this->whenLoaded('empresa', fn () => new EmpresaResource($this->empresa)),
            'ativo' => $this->ativo,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}

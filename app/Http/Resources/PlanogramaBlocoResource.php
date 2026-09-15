<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\PlanogramaBloco
 */
class PlanogramaBlocoResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'posicao_inicio' => $this->posicao_inicio,
            'largura' => $this->largura,
            'produto_auditoria' => $this->whenLoaded(
                'produtoAuditoria',
                fn () => $this->produtoAuditoria ? [
                    'id' => $this->produtoAuditoria->uuid,
                    'descricao' => $this->produtoAuditoria->descricao,
                    'imagem_url' => $this->produtoAuditoria->imagem_url,
                ] : null,
            ),
        ];
    }
}

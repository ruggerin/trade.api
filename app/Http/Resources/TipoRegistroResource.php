<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\TipoRegistro
 */
class TipoRegistroResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'descricao' => $this->descricao,
            'exige_foto' => $this->exige_foto,
            'permite_vincular_catalogo' => $this->permite_vincular_catalogo,
            'acao_obrigatoria' => $this->acao_obrigatoria,
            'escopo_acao' => $this->escopo_acao?->value,
            'campanha_auditoria_uuid' => $this->whenLoaded('campanhaAuditoria', fn () => $this->campanhaAuditoria?->uuid),
            // Ver App\Support\GranularidadeChecklist e docs/16-GRANULARIDADE-CHECKLIST-AUDITORIA.md.
            'granularidade_padrao' => $this->granularidade_padrao?->value,
            // Marca a coluna "Ruptura" da grade de coleta (Fase 2) — ver
            // docs/16-GRANULARIDADE-CHECKLIST-AUDITORIA.md §9.
            'eh_ruptura' => $this->eh_ruptura,
            'excecoes_granularidade' => $this->whenLoaded(
                'excecoesGranularidade',
                fn () => $this->excecoesGranularidade->map(fn ($excecao) => [
                    'secao_uuid' => $excecao->secao?->uuid,
                    'secao_descricao' => $excecao->secao?->descricao,
                    'granularidade' => $excecao->granularidade->value,
                ]),
            ),
            'campos' => CampoTipoRegistroResource::collection($this->whenLoaded('campos')),
            // Só vem preenchido pra quem pede como SUPERADMIN — mesmo padrão de
            // DepartamentoAuditoriaResource.
            'empresa' => $this->whenLoaded('empresa', fn () => new EmpresaResource($this->empresa)),
            'ativo' => $this->ativo,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}

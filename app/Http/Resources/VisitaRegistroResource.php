<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\VisitaRegistro
 */
class VisitaRegistroResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'tipo_registro' => $this->whenLoaded(
                'tipoRegistro',
                fn () => $this->tipoRegistro ? ['id' => $this->tipoRegistro->uuid, 'descricao' => $this->tipoRegistro->descricao] : null,
            ),
            'produto_auditoria' => $this->whenLoaded(
                'produtoAuditoria',
                fn () => $this->produtoAuditoria ? [
                    'id' => $this->produtoAuditoria->uuid,
                    'descricao' => $this->produtoAuditoria->descricao,
                ] : null,
            ),
            // Vínculo opcional a um recorte mais amplo do catálogo — no máximo um destes três
            // vem preenchido, conforme `tipo_vinculo` (mesmo padrão de CampanhaItemResource).
            'tipo_vinculo' => $this->tipo_vinculo,
            'secao' => $this->whenLoaded(
                'secao',
                fn () => $this->secao ? ['id' => $this->secao->uuid, 'descricao' => $this->secao->descricao] : null,
            ),
            'departamento' => $this->whenLoaded(
                'departamento',
                fn () => $this->departamento ? ['id' => $this->departamento->uuid, 'descricao' => $this->departamento->descricao] : null,
            ),
            'marca' => $this->whenLoaded(
                'marca',
                fn () => $this->marca ? ['id' => $this->marca->uuid, 'descricao' => $this->marca->descricao] : null,
            ),
            'ruptura' => $this->ruptura,
            'observacao' => $this->observacao,
            'valores_campos' => $this->valores_campos,
            'imagem_url' => $this->imagem_path
                ? url("/api/visitas/{$this->visita->uuid}/registros/{$this->uuid}/imagem")
                : null,
            // Soft — a linha continua existindo mesmo cancelada (rastro histórico). Ver
            // App\Support\CancelamentoRegistro.
            'cancelado_em' => $this->cancelado_em,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}

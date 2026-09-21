<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\ProdutoAuditoria
 */
class ProdutoAuditoriaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'descricao' => $this->descricao,
            'codigo_barras' => $this->codigo_barras,
            'codigo_externo' => $this->codigo_externo,
            'imagem_url' => $this->imagem_url,
            'departamento' => $this->whenLoaded(
                'departamento',
                fn () => $this->departamento ? ['id' => $this->departamento->uuid, 'descricao' => $this->departamento->descricao] : null,
            ),
            'secao' => $this->whenLoaded(
                'secao',
                fn () => $this->secao ? ['id' => $this->secao->uuid, 'descricao' => $this->secao->descricao] : null,
            ),
            'marca' => $this->whenLoaded(
                'marca',
                fn () => $this->marca ? ['id' => $this->marca->uuid, 'descricao' => $this->marca->descricao] : null,
            ),
            'nivel_exibicao' => $this->whenLoaded(
                'nivelExibicao',
                fn () => $this->nivelExibicao ? ['id' => $this->nivelExibicao->uuid, 'descricao' => $this->nivelExibicao->descricao] : null,
            ),
            'produto_final' => $this->produto_final,
            'produto_chave' => $this->produto_chave,
            'gerar_via_secoes_marcas' => $this->gerar_via_secoes_marcas,
            'peso_kg' => $this->peso_kg,
            'propriedade' => $this->propriedade,
            // Só carregado quando quem pede é SUPERADMIN (filtro/coluna de empresa no admin web).
            'empresa' => $this->whenLoaded('empresa', fn () => new EmpresaResource($this->empresa)),
            'ativo' => $this->ativo,
            // PENDENTE/REJEITADO só quando criado por um promotor em modo REQUER_APROVACAO —
            // ver docs/14-SORTIMENTO-PONTO-VENDA.md §9.2.
            'status_aprovacao' => $this->status_aprovacao,
            'criado_por' => $this->whenLoaded('criadoPor', fn () => $this->criadoPor ? ['id' => $this->criadoPor->uuid, 'nome' => $this->criadoPor->nome] : null),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}

<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\SortimentoPontoVenda
 */
class SortimentoPontoVendaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'tipo_item' => $this->tipo_item,
            'produto' => $this->whenLoaded('produto', fn () => $this->produto ? [
                'id' => $this->produto->uuid,
                'descricao' => $this->produto->descricao,
                'propriedade' => $this->produto->propriedade,
                'imagem_url' => $this->produto->imagem_url,
                'codigo_barras' => $this->produto->codigo_barras,
                // Ver docs/16-GRANULARIDADE-CHECKLIST-AUDITORIA.md §6.
                'produto_chave' => $this->produto->produto_chave,
                // Usado pra agrupar por linha/seção na grade de coleta (Fase 2) — ver
                // docs/16-GRANULARIDADE-CHECKLIST-AUDITORIA.md §9.
                'secao_uuid' => $this->produto->secao?->uuid,
                'secao_descricao' => $this->produto->secao?->descricao,
                // Usado pra agrupar a aba Mix por departamento no app — ver
                // docs/27-BUSCA-MULTIPLA-DE-PRODUTOS.md.
                'departamento_uuid' => $this->produto->departamento?->uuid,
                'departamento_descricao' => $this->produto->departamento?->descricao,
            ] : null),
            'departamento' => $this->whenLoaded('departamento', fn () => $this->departamento ? ['id' => $this->departamento->uuid, 'descricao' => $this->departamento->descricao] : null),
            'secao' => $this->whenLoaded('secao', fn () => $this->secao ? ['id' => $this->secao->uuid, 'descricao' => $this->secao->descricao] : null),
            'marca' => $this->whenLoaded('marca', fn () => $this->marca ? ['id' => $this->marca->uuid, 'descricao' => $this->marca->descricao] : null),
            // NULL = cadastrado pelo admin web; preenchido = promotor pela visita.
            'usuario' => $this->whenLoaded('usuario', fn () => $this->usuario ? ['id' => $this->usuario->uuid, 'nome' => $this->usuario->nome] : null),
            'status_aprovacao' => $this->status_aprovacao,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}

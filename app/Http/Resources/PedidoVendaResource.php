<?php

namespace App\Http\Resources;

use App\Models\PedidoVendaItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Mesmo resource pra listagem e detalhe — `historico` e o produto de cada item só vêm no show.
 * Subtotal/total calculados aqui, nunca gravados (docs/38-PEDIDO-VENDEDOR.md §6).
 *
 * @mixin \App\Models\PedidoVenda
 */
class PedidoVendaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $itens = $this->relationLoaded('itens') ? $this->itens : null;

        return [
            'id' => $this->uuid,
            'status' => $this->status,
            'ponto_venda' => $this->whenLoaded('pontoVenda', fn () => $this->pontoVenda ? [
                'id' => $this->pontoVenda->uuid,
                'fantasia' => $this->pontoVenda->fantasia,
                'razao_social' => $this->pontoVenda->razao_social,
                'cnpj' => $this->pontoVenda->cnpj,
                'codigo_externo' => $this->pontoVenda->codigo_externo,
            ] : null),
            'vendedor' => $this->whenLoaded('criadoPor', fn () => $this->criadoPor
                ? ['id' => $this->criadoPor->uuid, 'nome' => $this->criadoPor->nome]
                : null),
            'visita_id' => $this->whenLoaded('visita', fn () => $this->visita?->uuid),
            'observacao' => $this->observacao,
            'total' => $itens ? round($itens->sum(fn (PedidoVendaItem $i) => $i->subtotal()), 2) : null,
            'total_itens' => $itens?->count(),
            'requer_autorizacao' => $itens ? $itens->contains('requer_autorizacao', true) : null,
            'itens' => $itens ? $itens->map(fn (PedidoVendaItem $i) => [
                'id' => $i->uuid,
                'produto' => $i->relationLoaded('produto') && $i->produto ? [
                    'id' => $i->produto->uuid,
                    'descricao' => $i->produto->descricao,
                    'codigo_barras' => $i->produto->codigo_barras,
                    'codigo_externo' => $i->produto->codigo_externo,
                    'imagem_url' => $i->produto->imagem_url,
                ] : null,
                'quantidade' => (float) $i->quantidade,
                'preco_tabela' => (float) $i->preco_tabela,
                'desconto_maximo_pct' => $i->desconto_maximo_pct !== null ? (float) $i->desconto_maximo_pct : null,
                'preco_minimo' => $i->precoMinimoCalculado(),
                'preco' => (float) $i->preco,
                // Desconto efetivo do que foi digitado sobre a tabela (negativo = acima da tabela).
                'desconto_pct' => (float) $i->preco_tabela > 0
                    ? round((1 - (float) $i->preco / (float) $i->preco_tabela) * 100, 2)
                    : 0.0,
                'subtotal' => $i->subtotal(),
                'requer_autorizacao' => $i->requer_autorizacao,
            ])->values() : null,
            'historico' => $this->whenLoaded('historicos', fn () => $this->historicos->map(fn ($h) => [
                'id' => $h->uuid,
                'acao' => $h->acao,
                'descricao' => $h->descricao,
                'motivo' => $h->motivo,
                'snapshot' => $h->snapshot,
                'usuario' => $h->usuario ? ['id' => $h->usuario->uuid, 'nome' => $h->usuario->nome] : null,
                'created_at' => $h->created_at,
            ])),
            'concluido_em' => $this->concluido_em,
            'concluido_por' => $this->whenLoaded('concluidoPor', fn () => $this->concluidoPor
                ? ['id' => $this->concluidoPor->uuid, 'nome' => $this->concluidoPor->nome]
                : null),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}

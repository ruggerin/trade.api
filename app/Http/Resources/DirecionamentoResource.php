<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Direcionamento
 */
class DirecionamentoResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'descricao' => $this->descricao,
            'vigencia_inicio' => $this->vigencia_inicio,
            'vigencia_fim' => $this->vigencia_fim,
            'ativo' => $this->ativo,
            // Presente só quando a query usou withCount('ordensServico') — DirecionamentoController::index,
            // pra não pesar a listagem com o resumo de progresso inteiro (esse fica só no show()).
            'ordens_servico_count' => $this->ordens_servico_count ?? null,
            'filtros' => [
                'pontos_venda' => $this->whenLoaded(
                    'pontosVenda',
                    fn () => $this->pontosVenda->map(fn ($p) => ['id' => $p->uuid, 'fantasia' => $p->fantasia])->values(),
                ),
                'redes_loja' => $this->whenLoaded(
                    'redesLoja',
                    fn () => $this->redesLoja->map(fn ($r) => ['id' => $r->uuid, 'descricao' => $r->descricao])->values(),
                ),
                'promotores' => $this->whenLoaded(
                    'promotores',
                    fn () => $this->promotores->map(fn ($u) => ['id' => $u->uuid, 'nome' => $u->nome])->values(),
                ),
            ],
            // obrigatorio/calcula_percentual_compliance vêm do pivot (docs/25 §2 decisão 9), não
            // do TipoRegistro em si.
            'formularios' => $this->whenLoaded(
                'formularios',
                fn () => $this->formularios->map(fn ($f) => [
                    'tipo_registro' => ['id' => $f->uuid, 'descricao' => $f->descricao],
                    'obrigatorio' => (bool) $f->pivot->obrigatorio,
                    'calcula_percentual_compliance' => (bool) $f->pivot->calcula_percentual_compliance,
                ])->values(),
            ),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}

<?php

namespace App\Http\Controllers;

use App\Http\Requests\Pedido\StorePedidoEntregaRequest;
use App\Http\Requests\Pedido\StorePedidoRequest;
use App\Models\Pedido;
use App\Models\PontoVenda;
use App\Models\ProdutoAuditoria;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * Pedidos do ERP por loja — docs/28-RELATORIOS-FEEDBACK-HISTORICO.md §4.2. Escrita só pelo
 * integrador externo (um usuário ADMIN de serviço, ou perfil com `pedidos.gerenciar`); leitura
 * aberta a qualquer autenticado da empresa, só consulta (é o que o promotor vê ao entrar na loja).
 */
class PedidoController extends Controller
{
    /**
     * Idempotente por (empresa, numero_pedido): reenviar o mesmo pedido substitui cabeçalho e
     * itens em vez de duplicar (201 na criação, 200 na atualização). As entregas nunca são
     * tocadas aqui — chegam por `entregar`.
     */
    public function store(StorePedidoRequest $request): JsonResponse
    {
        $dados = $request->validated();
        $empresaId = $request->user()->empresa_id;
        $pontoVendaId = PontoVenda::where('uuid', $dados['ponto_venda_uuid'])->value('id');

        $pedido = DB::transaction(function () use ($dados, $empresaId, $pontoVendaId) {
            $pedido = Pedido::where('numero_pedido', $dados['numero_pedido'])->first();
            $atributos = [
                'ponto_venda_id' => $pontoVendaId,
                'numero_nf' => $dados['numero_nf'] ?? null,
                'data_pedido' => $dados['data_pedido'],
                'observacao' => $dados['observacao'] ?? null,
            ];

            if ($pedido) {
                $pedido->update($atributos);
                $pedido->itens()->delete();
            } else {
                $pedido = Pedido::create(['empresa_id' => $empresaId, 'numero_pedido' => $dados['numero_pedido']] + $atributos);
            }

            // Resolve o vínculo com o catálogo pelo código externo; sem match o item fica com a
            // descrição crua do ERP (produto_id null) em vez de derrubar o pedido inteiro.
            $codigos = collect($dados['itens'])->pluck('codigo_externo_produto')->unique();
            $produtos = ProdutoAuditoria::whereIn('codigo_externo', $codigos)->pluck('id', 'codigo_externo');

            foreach ($dados['itens'] as $item) {
                $pedido->itens()->create([
                    'produto_id' => $produtos[$item['codigo_externo_produto']] ?? null,
                    'codigo_externo_produto' => $item['codigo_externo_produto'],
                    'descricao_produto' => $item['descricao_produto'],
                    'quantidade' => $item['quantidade'],
                ]);
            }

            return $pedido;
        });

        return response()->json(
            ['pedido' => $this->formatar($pedido->load(['itens.produto', 'entregas', 'pontoVenda']))],
            $pedido->wasRecentlyCreated ? 201 : 200,
        );
    }

    /** Idempotente por (pedido, data_entrega): o mesmo horário reenviado não duplica a entrega. */
    public function entregar(StorePedidoEntregaRequest $request, Pedido $pedido): JsonResponse
    {
        $dados = $request->validated();

        $entrega = $pedido->entregas()->firstOrCreate(
            ['data_entrega' => $dados['data_entrega']],
            ['observacao' => $dados['observacao'] ?? null],
        );

        return response()->json(
            ['pedido' => $this->formatar($pedido->load(['itens.produto', 'entregas', 'pontoVenda']))],
            $entrega->wasRecentlyCreated ? 201 : 200,
        );
    }

    /** Pedidos da loja, mais recentes primeiro — consulta do promotor (e do admin no detalhe do PDV). */
    public function porPontoVenda(PontoVenda $pontoVenda): JsonResponse
    {
        $pedidos = Pedido::query()
            ->where('ponto_venda_id', $pontoVenda->id)
            ->with(['itens.produto', 'entregas'])
            ->orderByDesc('data_pedido')
            ->orderByDesc('id')
            ->paginate(10);

        return response()->json([
            'pedidos' => collect($pedidos->items())->map(fn (Pedido $p) => $this->formatar($p))->values(),
            'meta' => [
                'current_page' => $pedidos->currentPage(),
                'last_page' => $pedidos->lastPage(),
                'per_page' => $pedidos->perPage(),
                'total' => $pedidos->total(),
            ],
        ]);
    }

    private function formatar(Pedido $pedido): array
    {
        $ultimaEntrega = $pedido->entregas->last();

        return [
            'id' => $pedido->uuid,
            'numero_pedido' => $pedido->numero_pedido,
            'numero_nf' => $pedido->numero_nf,
            'data_pedido' => $pedido->data_pedido->toDateString(),
            'observacao' => $pedido->observacao,
            'ponto_venda_id' => $pedido->relationLoaded('pontoVenda') ? $pedido->pontoVenda->uuid : null,
            // Nunca persistido — pelo menos uma entrega = entregue (mesmo raciocínio de StatusApuracaoMeta).
            'status' => $pedido->entregas->isEmpty() ? 'PENDENTE' : 'ENTREGUE',
            'entregue_em' => $ultimaEntrega?->data_entrega,
            'itens' => $pedido->itens->map(fn ($i) => [
                'id' => $i->uuid,
                'codigo_externo_produto' => $i->codigo_externo_produto,
                'descricao_produto' => $i->produto?->descricao ?? $i->descricao_produto,
                'produto_id' => $i->produto?->uuid,
                'quantidade' => $i->quantidade,
            ])->values(),
            'entregas' => $pedido->entregas->map(fn ($e) => [
                'id' => $e->uuid,
                'data_entrega' => $e->data_entrega,
                'observacao' => $e->observacao,
            ])->values(),
        ];
    }
}

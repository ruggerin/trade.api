<?php

namespace App\Http\Controllers;

use App\Http\Requests\Pedido\StorePedidoEntregaRequest;
use App\Http\Requests\Pedido\StorePedidoRequest;
use App\Models\Pedido;
use App\Models\PontoVenda;
use App\Models\ProdutoAuditoria;
use App\Support\NotificacoesPedido;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
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

        $previsaoAnterior = null;
        $pedido = DB::transaction(function () use ($dados, $empresaId, $pontoVendaId, &$previsaoAnterior) {
            $pedido = Pedido::where('numero_pedido', $dados['numero_pedido'])->first();
            $previsaoAnterior = $pedido?->data_previsao_entrega?->toDateString();
            $atributos = [
                'ponto_venda_id' => $pontoVendaId,
                'numero_nf' => $dados['numero_nf'] ?? null,
                'data_pedido' => $dados['data_pedido'],
                'data_previsao_entrega' => $dados['data_previsao_entrega'] ?? null,
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

        $pedido->load(['itens.produto', 'entregas', 'pontoVenda']);

        // Aviso "pedido a caminho" (docs/54 §5 Fase 3): quando a previsão aparece ou muda (o ERP
        // revisou a data) — reenvio igual não avisa de novo; pedido já entregue não avisa.
        $previsaoNova = $pedido->data_previsao_entrega?->toDateString();
        if ($previsaoNova !== null && $previsaoNova !== $previsaoAnterior && $pedido->entregas->isEmpty()) {
            NotificacoesPedido::avisar($pedido, NotificacoesPedido::PREVISTO);
        }

        return response()->json(
            ['pedido' => $this->formatar($pedido)],
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

        // Aviso "pedido entregue" — só na primeira vez (reenvio da mesma entrega não avisa).
        if ($entrega->wasRecentlyCreated) {
            NotificacoesPedido::avisar($pedido, NotificacoesPedido::ENTREGUE);
        }

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

    /** Um pedido só — detalhe no app, aberto pela lista da loja ou pelo sino (docs/54 §5). */
    public function show(Pedido $pedido): JsonResponse
    {
        return response()->json(['pedido' => $this->formatar($pedido->load(['itens.produto', 'entregas', 'pontoVenda']))]);
    }

    /**
     * Avisos de pedido não lidos do usuário logado (docs/54 §5 Fase 3) — o sino do app soma com os
     * comentários não lidos e mostra os dois juntos.
     */
    public function notificacoes(Request $request): JsonResponse
    {
        $avisos = DB::table('notificacoes_pedido as n')
            ->join('pedidos as p', 'p.id', '=', 'n.pedido_id')
            ->leftJoin('pontos_venda as pv', 'pv.id', '=', 'p.ponto_venda_id')
            ->where('n.usuario_id', $request->user()->id)
            ->whereNull('n.lida_em')
            ->orderByDesc('n.created_at')
            ->orderByDesc('n.id')
            ->limit(50)
            ->get(['n.uuid', 'n.tipo', 'n.data_previsao', 'n.created_at', 'p.uuid as pedido_uuid', 'p.numero_pedido', 'pv.fantasia']);

        return response()->json([
            'total' => $avisos->count(),
            'avisos' => $avisos->map(fn ($a) => [
                'id' => $a->uuid,
                'tipo' => $a->tipo,
                'pedido_id' => $a->pedido_uuid,
                'numero_pedido' => $a->numero_pedido,
                'ponto_venda' => $a->fantasia,
                'data_previsao' => $a->data_previsao ? substr((string) $a->data_previsao, 0, 10) : null,
                'em' => Carbon::parse($a->created_at)->toIso8601String(),
            ])->values(),
        ]);
    }

    /** Abriu o detalhe do pedido: os avisos dele (desse usuário) viram lidos. */
    public function marcarLido(Request $request, Pedido $pedido): JsonResponse
    {
        DB::table('notificacoes_pedido')
            ->where('usuario_id', $request->user()->id)
            ->where('pedido_id', $pedido->id)
            ->whereNull('lida_em')
            ->update(['lida_em' => now()]);

        return response()->json(status: 204);
    }

    private function formatar(Pedido $pedido): array
    {
        $ultimaEntrega = $pedido->entregas->last();

        return [
            'id' => $pedido->uuid,
            'numero_pedido' => $pedido->numero_pedido,
            'numero_nf' => $pedido->numero_nf,
            'data_pedido' => $pedido->data_pedido->toDateString(),
            // Previsão de chegada na loja (ERP, docs/54 §4) — null quando o ERP não mandou.
            'data_previsao_entrega' => $pedido->data_previsao_entrega?->toDateString(),
            'observacao' => $pedido->observacao,
            'ponto_venda_id' => $pedido->relationLoaded('pontoVenda') ? $pedido->pontoVenda->uuid : null,
            'ponto_venda' => $pedido->relationLoaded('pontoVenda') ? $pedido->pontoVenda->fantasia : null,
            // Nunca persistido (mesmo raciocínio de StatusApuracaoMeta): ENTREGUE com pelo menos uma
            // entrega; senão A_CAMINHO, ou ATRASADO se a previsão já passou (docs/54 §4).
            'status' => NotificacoesPedido::status($pedido),
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

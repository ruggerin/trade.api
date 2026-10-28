<?php

namespace App\Http\Controllers;

use App\Http\Requests\Pedido\StorePedidoEntregaRequest;
use App\Http\Requests\Pedido\StorePedidoRequest;
use App\Models\Pedido;
use App\Models\PontoVenda;
use App\Support\Faturamento;
use App\Support\NotificacoesPedido;
use App\Support\PedidosErp;
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
     * tocadas aqui — chegam por `entregar`. Regra em App\Support\PedidosErp (a mesma da API de
     * integração, docs/96).
     */
    public function store(StorePedidoRequest $request): JsonResponse
    {
        $dados = $request->validated();
        $pontoVendaId = PontoVenda::where('uuid', $dados['ponto_venda_uuid'])->value('id');

        [$pedido, $criado] = PedidosErp::gravar($request->user()->empresa, $pontoVendaId, $dados);

        return response()->json(
            ['pedido' => $this->formatar($pedido, $this->verValores($request))],
            $criado ? 201 : 200,
        );
    }

    /** Idempotente por (pedido, data_entrega): o mesmo horário reenviado não duplica a entrega. */
    public function entregar(StorePedidoEntregaRequest $request, Pedido $pedido): JsonResponse
    {
        $dados = $request->validated();

        [, $criada] = PedidosErp::entregar($pedido, $dados['data_entrega'], $dados['observacao'] ?? null);

        return response()->json(
            ['pedido' => $this->formatar($pedido->load(['itens.produto', 'entregas', 'pontoVenda']), $this->verValores($request))],
            $criada ? 201 : 200,
        );
    }
    /** Pedidos da loja, mais recentes primeiro — consulta do promotor (e do admin no detalhe do PDV). */
    public function porPontoVenda(Request $request, PontoVenda $pontoVenda): JsonResponse
    {
        $comValores = $this->verValores($request);
        $pedidos = Pedido::query()
            ->where('ponto_venda_id', $pontoVenda->id)
            ->with(['itens.produto', 'entregas'])
            ->orderByDesc('data_pedido')
            ->orderByDesc('id')
            ->paginate(10);

        return response()->json([
            'pedidos' => collect($pedidos->items())->map(fn (Pedido $p) => $this->formatar($p, $comValores))->values(),
            'meta' => [
                'current_page' => $pedidos->currentPage(),
                'last_page' => $pedidos->lastPage(),
                'per_page' => $pedidos->perPage(),
                'total' => $pedidos->total(),
            ],
        ]);
    }

    /** Um pedido só — detalhe no app, aberto pela lista da loja ou pelo sino (docs/54 §5). */
    public function show(Request $request, Pedido $pedido): JsonResponse
    {
        return response()->json(['pedido' => $this->formatar($pedido->load(['itens.produto', 'entregas', 'pontoVenda']), $this->verValores($request))]);
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

    /**
     * O mesmo endpoint serve o promotor (app) e o admin: valor, lucro e status de faturamento só
     * saem pra quem passa em Faturamento::podeVer — a chave nem existe no JSON do promotor.
     */
    private function verValores(Request $request): bool
    {
        return Faturamento::podeVer($request->user());
    }

    /** @return array<string, mixed> */
    private function valoresDoPedido(Pedido $pedido, bool $comValores): array
    {
        if (! $comValores) {
            return [];
        }

        return [
            'valor_total' => $pedido->valor_total,
            'valor_lucro_total' => $pedido->valor_lucro_total,
            // Margem calculada na leitura, nunca gravada; nula quando falta total ou lucro.
            'margem_pct' => $pedido->valor_total && $pedido->valor_lucro_total !== null
                ? round($pedido->valor_lucro_total / $pedido->valor_total * 100, 1)
                : null,
            'status_faturamento' => $pedido->status_faturamento,
            'faturado_em' => $pedido->faturado_em?->toDateString(),
            'valor_divergente' => $pedido->valor_divergente,
        ];
    }

    private function formatar(Pedido $pedido, bool $comValores = false): array
    {
        $ultimaEntrega = $pedido->entregas->last();

        return $this->valoresDoPedido($pedido, $comValores) + [
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
            ] + ($comValores ? [
                'preco_unitario' => $i->preco_unitario,
                'subtotal' => $i->subtotal,
                'custo_unitario' => $i->custo_unitario,
                'valor_lucro' => $i->valor_lucro,
                'origem_lucro' => $i->origem_lucro,
                'valor_divergente' => $i->valor_divergente,
            ] : []))->values(),
            'entregas' => $pedido->entregas->map(fn ($e) => [
                'id' => $e->uuid,
                'data_entrega' => $e->data_entrega,
                'observacao' => $e->observacao,
            ])->values(),
        ];
    }
}

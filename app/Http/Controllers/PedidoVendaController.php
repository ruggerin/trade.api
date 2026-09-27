<?php

namespace App\Http\Controllers;

use App\Enums\AcaoHistoricoPedidoVenda;
use App\Enums\Permissao;
use App\Enums\StatusPedidoVenda;
use App\Enums\StatusVisita;
use App\Enums\UserType;
use App\Http\Requests\PedidoVenda\SalvarPedidoVendaRequest;
use App\Http\Resources\PedidoVendaResource;
use App\Models\PedidoVenda;
use App\Models\PedidoVendaHistorico;
use App\Models\PedidoVendaItem;
use App\Models\PontoVenda;
use App\Models\ProdutoAuditoria;
use App\Models\Usuario;
use App\Models\Visita;
use App\Support\PedidoVendaSemVisita;
use App\Support\PermissaoPedidoVenda;
use App\Support\VisibilidadePontosVenda;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Pedido de Venda — o promotor em "modo Vendedor" (permissão pedidos_venda.criar) monta um
 * pedido com preço; item abaixo do mínimo (tabela × (1 − desconto máximo)) exige autorização de
 * quem tem pedidos_venda.aprovar, nunca o próprio autor. v1 sem ERP, sem estoque, preço único
 * por produto no catálogo. Ver docs/38-PEDIDO-VENDEDOR.md.
 *
 * Permissão checada aqui dentro (não no middleware `permissao:`): o EnsurePermissao nunca libera
 * PROMOTOR, e o vendedor é um PROMOTOR — ver App\Support\PermissaoPedidoVenda.
 */
class PedidoVendaController extends Controller
{
    private const RELACOES_DETALHE = [
        'itens.produto', 'historicos.usuario', 'pontoVenda', 'criadoPor', 'concluidoPor', 'visita',
    ];

    public function index(Request $request): JsonResponse
    {
        $usuario = $request->user();
        $this->exigirModulo($usuario);
        abort_unless(PermissaoPedidoVenda::acessa($usuario), 403, 'Você não tem permissão para ver pedidos de venda.');

        $query = $this->visiveis($usuario)
            ->when($request->filled('status'), function ($q) use ($request) {
                $status = $request->input('status');
                is_array($status) ? $q->whereIn('status', $status) : $q->where('status', $status);
            })
            ->when($request->filled('vendedor_uuid'), fn ($q) => $q->whereHas(
                'criadoPor',
                fn ($u) => $u->where('uuid', $request->input('vendedor_uuid')),
            ))
            ->when($request->filled('ponto_venda_uuid'), fn ($q) => $q->whereHas(
                'pontoVenda',
                fn ($p) => $p->where('uuid', $request->input('ponto_venda_uuid')),
            ))
            ->when($request->filled('visita_uuid'), fn ($q) => $q->whereHas(
                'visita',
                fn ($v) => $v->where('uuid', $request->input('visita_uuid')),
            ))
            ->when($request->filled('data_inicio'), fn ($q) => $q->whereDate('created_at', '>=', $request->input('data_inicio')))
            ->when($request->filled('data_fim'), fn ($q) => $q->whereDate('created_at', '<=', $request->input('data_fim')))
            ->when($request->filled('busca'), function ($q) use ($request) {
                $termo = '%'.addcslashes($request->string('busca'), '%_\\').'%';
                // Agrupado pra o OR não escapar da correlação do whereHas.
                $q->whereHas('pontoVenda', fn ($p) => $p->where(fn ($p) => $p
                    ->where('fantasia', 'ilike', $termo)
                    ->orWhere('razao_social', 'ilike', $termo)
                    ->orWhere('cnpj', 'ilike', $termo)));
            })
            ->with(['itens.produto', 'pontoVenda', 'criadoPor', 'visita'])
            // Fila de autorização primeiro (é o que pede ação), depois o mais recente.
            ->orderByRaw("CASE WHEN status = 'PENDENTE_AUTORIZACAO' THEN 0 ELSE 1 END")
            ->latest()
            ->orderByDesc('id');

        $pedidos = $query->paginate(min(max($request->integer('per_page', 15), 1), 500));

        return response()->json([
            'pedidos_venda' => PedidoVendaResource::collection($pedidos->items()),
            'meta' => [
                'current_page' => $pedidos->currentPage(),
                'last_page' => $pedidos->lastPage(),
                'per_page' => $pedidos->perPage(),
                'total' => $pedidos->total(),
            ],
            'resumo' => $this->resumo($usuario),
            'permissoes' => [
                'criar' => PermissaoPedidoVenda::tem($usuario, Permissao::PEDIDOS_VENDA_CRIAR),
                'aprovar' => PermissaoPedidoVenda::tem($usuario, Permissao::PEDIDOS_VENDA_APROVAR),
            ],
        ]);
    }

    /** Quem aparece no filtro "vendedor" — quem já criou algum pedido visível. */
    public function vendedores(Request $request): JsonResponse
    {
        $usuario = $request->user();
        $this->exigirModulo($usuario);
        abort_unless(PermissaoPedidoVenda::acessa($usuario), 403);

        $ids = $this->visiveis($usuario)->distinct()->pluck('criado_por_id');
        $vendedores = Usuario::whereIn('id', $ids)->orderBy('nome')->get(['uuid', 'nome']);

        return response()->json([
            'vendedores' => $vendedores->map(fn (Usuario $u) => ['id' => $u->uuid, 'nome' => $u->nome]),
        ]);
    }

    public function show(Request $request, PedidoVenda $pedidoVenda): JsonResponse
    {
        $this->exigirVisivel($request->user(), $pedidoVenda);

        return $this->respostaDetalhe($request, $pedidoVenda);
    }

    public function store(SalvarPedidoVendaRequest $request): JsonResponse
    {
        $usuario = $request->user();
        $this->exigirModulo($usuario);
        abort_unless(
            PermissaoPedidoVenda::tem($usuario, Permissao::PEDIDOS_VENDA_CRIAR),
            403,
            'Você não tem permissão para criar pedidos de venda.',
        );

        $dados = $request->validated();
        $pontoVenda = PontoVenda::where('uuid', $dados['ponto_venda_uuid'])->firstOrFail();

        // Mesmo cuidado do "+ Compromisso" self-agendado: o PDV precisa ser um dos que o promotor
        // realmente enxerga, não só confiar no app.
        if ($usuario->user_type === UserType::PROMOTOR && ! VisibilidadePontosVenda::visivelParaPromotor($pontoVenda->id, $usuario)) {
            throw ValidationException::withMessages(['ponto_venda_uuid' => 'Você não atende este ponto de venda.']);
        }

        $visita = null;
        if (! empty($dados['visita_uuid'])) {
            $visita = Visita::where('uuid', $dados['visita_uuid'])->first();
            if (! $visita || $visita->ponto_venda_id !== $pontoVenda->id || $visita->usuario_id !== $usuario->id) {
                throw ValidationException::withMessages(['visita_uuid' => 'Visita não pertence a você ou a este ponto de venda.']);
            }
        }

        // Pedido fora de visita só com o parâmetro ligado (App\Support\PedidoVendaSemVisita) — sem
        // ele, o promotor precisa estar numa visita EM ANDAMENTO naquela loja (uma visita já
        // encerrada não conta, senão qualquer visita antiga "liberaria" o pedido).
        if ($usuario->user_type === UserType::PROMOTOR && ! PedidoVendaSemVisita::permitido($usuario->empresa)) {
            if (! $visita) {
                throw ValidationException::withMessages(['visita_uuid' => 'Sua empresa só permite tirar pedido durante uma visita — faça o check-in na loja primeiro.']);
            }
            if ($visita->status !== StatusVisita::ABERTA) {
                throw ValidationException::withMessages(['visita_uuid' => 'Esta visita já foi encerrada — o pedido precisa ser tirado durante uma visita em andamento.']);
            }
        }

        $itens = $this->montarItens($dados['itens']);

        $pedido = DB::transaction(function () use ($usuario, $pontoVenda, $visita, $dados, $itens) {
            $pedido = PedidoVenda::create([
                'empresa_id' => $usuario->empresa_id,
                'ponto_venda_id' => $pontoVenda->id,
                'criado_por_id' => $usuario->id,
                'visita_id' => $visita?->id,
                'status' => StatusPedidoVenda::RASCUNHO,
                'observacao' => $dados['observacao'] ?? null,
            ]);
            $pedido->itens()->createMany($itens);

            $total = count($itens);
            $this->registrarHistorico($usuario, $pedido, AcaoHistoricoPedidoVenda::CRIADO, sprintf(
                'Pedido criado para %s com %d %s',
                $pontoVenda->fantasia,
                $total,
                $total === 1 ? 'item' : 'itens',
            ));

            return $pedido;
        });

        return $this->respostaDetalhe($request, $pedido, 201);
    }

    /**
     * Substitui itens/observação. Pedido APROVADO volta pra RASCUNHO — a aprovação anterior não
     * cobre o que foi editado depois (§7). PENDENTE_AUTORIZACAO fica travado até alguém decidir.
     */
    public function update(SalvarPedidoVendaRequest $request, PedidoVenda $pedidoVenda): JsonResponse
    {
        $usuario = $request->user();
        $this->exigirVisivel($usuario, $pedidoVenda);
        abort_unless($this->podeEditar($usuario, $pedidoVenda), 403, 'Você não pode editar este pedido.');
        $this->exigirEditavel($pedidoVenda);

        $dados = $request->validated();
        $itens = $this->montarItens($dados['itens']);

        DB::transaction(function () use ($usuario, $pedidoVenda, $dados, $itens) {
            $estavaAprovado = $pedidoVenda->status === StatusPedidoVenda::APROVADO;

            $pedidoVenda->update([
                'observacao' => $dados['observacao'] ?? null,
                'status' => StatusPedidoVenda::RASCUNHO,
            ]);
            $pedidoVenda->itens()->delete();
            $pedidoVenda->itens()->createMany($itens);

            $total = count($itens);
            $descricao = sprintf('Pedido editado (%d %s)', $total, $total === 1 ? 'item' : 'itens');
            if ($estavaAprovado) {
                $descricao .= ' — aprovação anterior invalidada, voltou para rascunho';
            }
            $this->registrarHistorico($usuario, $pedidoVenda, AcaoHistoricoPedidoVenda::ITEM_ALTERADO, $descricao);
        });

        return $this->respostaDetalhe($request, $pedidoVenda->refresh());
    }

    /**
     * "Enviar" (nenhum item abaixo do mínimo → APROVADO direto) ou "Solicitar autorização"
     * (→ PENDENTE_AUTORIZACAO) — um endpoint só, o front decide o rótulo do botão pelo
     * `requer_autorizacao` do pedido. Revalida contra o preço atual do catálogo antes: nunca
     * confia só no snapshot de quando o item foi digitado.
     */
    public function enviar(Request $request, PedidoVenda $pedidoVenda): JsonResponse
    {
        $usuario = $request->user();
        $this->exigirVisivel($usuario, $pedidoVenda);
        abort_unless($this->podeEditar($usuario, $pedidoVenda), 403, 'Você não pode enviar este pedido.');
        $this->exigirStatus($pedidoVenda, StatusPedidoVenda::RASCUNHO, 'Só um pedido em rascunho pode ser enviado.');

        DB::transaction(function () use ($usuario, $pedidoVenda) {
            PedidoVenda::whereKey($pedidoVenda->id)->lockForUpdate()->first();
            $alterados = $this->revalidarItens($pedidoVenda);
            $pedidoVenda->load('itens.produto');

            if ($alterados > 0) {
                $this->registrarHistorico($usuario, $pedidoVenda, AcaoHistoricoPedidoVenda::ITEM_ALTERADO, sprintf(
                    'Preço de tabela atualizado em %d %s no envio (o catálogo mudou desde a digitação)',
                    $alterados,
                    $alterados === 1 ? 'item' : 'itens',
                ));
            }

            $abaixo = $pedidoVenda->itens->where('requer_autorizacao', true)->count();

            if ($abaixo === 0) {
                $pedidoVenda->update(['status' => StatusPedidoVenda::APROVADO]);
                $this->registrarHistorico(
                    $usuario, $pedidoVenda, AcaoHistoricoPedidoVenda::APROVADO,
                    'Pedido enviado — nenhum item abaixo do preço mínimo, aprovado automaticamente',
                    snapshot: $this->snapshot($pedidoVenda),
                );
            } else {
                $pedidoVenda->update(['status' => StatusPedidoVenda::PENDENTE_AUTORIZACAO]);
                $this->registrarHistorico(
                    $usuario, $pedidoVenda, AcaoHistoricoPedidoVenda::AUTORIZACAO_SOLICITADA,
                    sprintf('Autorização solicitada — %d %s abaixo do preço mínimo', $abaixo, $abaixo === 1 ? 'item' : 'itens'),
                    snapshot: $this->snapshot($pedidoVenda),
                );
            }
        });

        return $this->respostaDetalhe($request, $pedidoVenda->refresh());
    }

    public function aprovar(Request $request, PedidoVenda $pedidoVenda): JsonResponse
    {
        $usuario = $request->user();
        $dados = $request->validate(['motivo' => ['nullable', 'string', 'max:2000']]);
        $this->exigirAprovador($usuario, $pedidoVenda);
        $this->exigirStatus($pedidoVenda, StatusPedidoVenda::PENDENTE_AUTORIZACAO, 'Este pedido não está aguardando autorização.');

        DB::transaction(function () use ($usuario, $pedidoVenda, $dados) {
            $pedidoVenda->update(['status' => StatusPedidoVenda::APROVADO]);
            $pedidoVenda->load('itens.produto');
            $this->registrarHistorico(
                $usuario, $pedidoVenda, AcaoHistoricoPedidoVenda::APROVADO, 'Preços autorizados',
                motivo: $dados['motivo'] ?? null, snapshot: $this->snapshot($pedidoVenda),
            );
        });

        return $this->respostaDetalhe($request, $pedidoVenda->refresh());
    }

    public function rejeitar(Request $request, PedidoVenda $pedidoVenda): JsonResponse
    {
        $usuario = $request->user();
        $dados = $request->validate(['motivo' => ['required', 'string', 'max:2000']], [
            'motivo.required' => 'Informe o motivo da rejeição.',
        ]);
        $this->exigirAprovador($usuario, $pedidoVenda);
        $this->exigirStatus($pedidoVenda, StatusPedidoVenda::PENDENTE_AUTORIZACAO, 'Este pedido não está aguardando autorização.');

        DB::transaction(function () use ($usuario, $pedidoVenda, $dados) {
            $pedidoVenda->update(['status' => StatusPedidoVenda::RASCUNHO]);
            $this->registrarHistorico(
                $usuario, $pedidoVenda, AcaoHistoricoPedidoVenda::REJEITADO,
                'Autorização rejeitada — pedido voltou para rascunho', motivo: $dados['motivo'],
            );
        });

        return $this->respostaDetalhe($request, $pedidoVenda->refresh());
    }

    /** APROVADO → CONCLUIDO: o pedido foi efetivado (na v1, repassado ao ERP por fora). */
    public function concluir(Request $request, PedidoVenda $pedidoVenda): JsonResponse
    {
        $usuario = $request->user();
        $this->exigirVisivel($usuario, $pedidoVenda);
        abort_unless($this->podeEditar($usuario, $pedidoVenda), 403, 'Você não pode concluir este pedido.');
        $this->exigirStatus($pedidoVenda, StatusPedidoVenda::APROVADO, 'Só um pedido aprovado pode ser concluído.');

        DB::transaction(function () use ($usuario, $pedidoVenda) {
            $pedidoVenda->update([
                'status' => StatusPedidoVenda::CONCLUIDO,
                'concluido_em' => now(),
                'concluido_por_id' => $usuario->id,
            ]);
            $this->registrarHistorico($usuario, $pedidoVenda, AcaoHistoricoPedidoVenda::CONCLUIDO, 'Pedido concluído');
        });

        return $this->respostaDetalhe($request, $pedidoVenda->refresh());
    }

    public function cancelar(Request $request, PedidoVenda $pedidoVenda): JsonResponse
    {
        $usuario = $request->user();
        $dados = $request->validate(['motivo' => ['nullable', 'string', 'max:2000']]);
        $this->exigirVisivel($usuario, $pedidoVenda);
        abort_unless($this->podeEditar($usuario, $pedidoVenda), 403, 'Você não pode cancelar este pedido.');
        if ($pedidoVenda->status->terminal()) {
            throw ValidationException::withMessages(['status' => 'Este pedido já foi concluído ou cancelado.']);
        }

        DB::transaction(function () use ($usuario, $pedidoVenda, $dados) {
            $pedidoVenda->update(['status' => StatusPedidoVenda::CANCELADO]);
            $this->registrarHistorico(
                $usuario, $pedidoVenda, AcaoHistoricoPedidoVenda::CANCELADO, 'Pedido cancelado',
                motivo: $dados['motivo'] ?? null,
            );
        });

        return $this->respostaDetalhe($request, $pedidoVenda->refresh());
    }

    private function respostaDetalhe(Request $request, PedidoVenda $pedido, int $status = 200): JsonResponse
    {
        $pedido->load(self::RELACOES_DETALHE);
        $usuario = $request->user();
        $podeEditar = $this->podeEditar($usuario, $pedido);
        $podeAprovar = $this->podeAprovar($usuario, $pedido);

        return response()->json([
            'pedido_venda' => new PedidoVendaResource($pedido),
            // O que o usuário atual pode fazer AGORA com este pedido — o front não recalcula regra.
            'permissoes' => [
                'editar' => $podeEditar && $pedido->status->editavel(),
                'enviar' => $podeEditar && $pedido->status === StatusPedidoVenda::RASCUNHO,
                'aprovar' => $podeAprovar && $pedido->status === StatusPedidoVenda::PENDENTE_AUTORIZACAO,
                'concluir' => $podeEditar && $pedido->status === StatusPedidoVenda::APROVADO,
                'cancelar' => $podeEditar && ! $pedido->status->terminal(),
                // Tem .aprovar mas é o autor — o front mostra o porquê em vez de só sumir o botão.
                'e_autor' => $pedido->criado_por_id === $usuario->id,
            ],
        ], $status);
    }

    private function visiveis(Usuario $usuario): Builder
    {
        return PedidoVenda::query()->when(
            ! PermissaoPedidoVenda::vePedidosDeTodos($usuario),
            fn ($q) => $q->where('criado_por_id', $usuario->id),
        );
    }

    /** 403 com o motivo certo — "módulo não contratado" não é falta de permissão do Perfil. */
    private function exigirModulo(Usuario $usuario): void
    {
        abort_unless(
            PermissaoPedidoVenda::moduloHabilitado($usuario),
            403,
            'O módulo Pedido de Venda não está habilitado para sua empresa. Fale com o suporte para contratar.',
        );
    }

    private function exigirVisivel(Usuario $usuario, PedidoVenda $pedido): void
    {
        $this->exigirModulo($usuario);
        abort_unless(PermissaoPedidoVenda::acessa($usuario), 403, 'Você não tem permissão para ver pedidos de venda.');
        abort_unless(
            PermissaoPedidoVenda::vePedidosDeTodos($usuario) || $pedido->criado_por_id === $usuario->id,
            404,
        );
    }

    /**
     * Autor com .criar edita/envia/conclui/cancela o próprio; quem tem .aprovar também mexe no dos
     * outros (caso 3 do §3: gestor corrige a quantidade de um pedido já aprovado).
     */
    private function podeEditar(Usuario $usuario, PedidoVenda $pedido): bool
    {
        if (PermissaoPedidoVenda::tem($usuario, Permissao::PEDIDOS_VENDA_APROVAR)) {
            return true;
        }

        return $pedido->criado_por_id === $usuario->id
            && PermissaoPedidoVenda::tem($usuario, Permissao::PEDIDOS_VENDA_CRIAR);
    }

    /** Nunca quem criou o pedido, mesmo tendo .aprovar (§7) — checado aqui, não só por permissão. */
    private function podeAprovar(Usuario $usuario, PedidoVenda $pedido): bool
    {
        return PermissaoPedidoVenda::tem($usuario, Permissao::PEDIDOS_VENDA_APROVAR)
            && $pedido->criado_por_id !== $usuario->id;
    }

    private function exigirAprovador(Usuario $usuario, PedidoVenda $pedido): void
    {
        $this->exigirVisivel($usuario, $pedido);
        abort_unless(PermissaoPedidoVenda::tem($usuario, Permissao::PEDIDOS_VENDA_APROVAR), 403, 'Você não tem permissão para autorizar pedidos.');
        abort_if($pedido->criado_por_id === $usuario->id, 403, 'Você não pode autorizar um pedido criado por você.');
    }

    private function exigirEditavel(PedidoVenda $pedido): void
    {
        if (! $pedido->status->editavel()) {
            throw ValidationException::withMessages(['status' => match ($pedido->status) {
                StatusPedidoVenda::PENDENTE_AUTORIZACAO => 'Pedido aguardando autorização — não pode ser editado até ser aprovado ou rejeitado.',
                default => 'Este pedido já foi concluído ou cancelado — para corrigir, crie um pedido novo.',
            }]);
        }
    }

    private function exigirStatus(PedidoVenda $pedido, StatusPedidoVenda $esperado, string $mensagem): void
    {
        if ($pedido->status !== $esperado) {
            throw ValidationException::withMessages(['status' => $mensagem]);
        }
    }

    /**
     * Resolve produtos, tira o snapshot de preço do catálogo e calcula `requer_autorizacao`.
     * Produto sem preço configurado não entra (§10 pergunta 2).
     *
     * @param  array<int, array{produto_uuid: string, quantidade: numeric, preco: numeric}>  $itens
     * @return list<array<string, mixed>>
     */
    private function montarItens(array $itens): array
    {
        $produtos = ProdutoAuditoria::query()
            ->whereIn('uuid', array_column($itens, 'produto_uuid'))
            ->get()
            ->keyBy('uuid');

        $erros = [];
        $linhas = [];

        foreach (array_values($itens) as $i => $item) {
            $produto = $produtos->get($item['produto_uuid']);

            if (! $produto || ! $produto->ativo) {
                $erros["itens.{$i}.produto_uuid"] = 'Produto não encontrado ou inativo.';

                continue;
            }
            if ($produto->preco_tabela === null) {
                $erros["itens.{$i}.produto_uuid"] = "\"{$produto->descricao}\" está sem preço configurado no catálogo.";

                continue;
            }

            $linhas[] = [
                'produto_id' => $produto->id,
                'quantidade' => $item['quantidade'],
                ...$this->calcularPreco($produto, (float) $item['preco']),
            ];
        }

        if ($erros) {
            throw ValidationException::withMessages($erros);
        }

        return $linhas;
    }

    /** @return array{preco_tabela: float, desconto_maximo_pct: float|null, preco: float, requer_autorizacao: bool} */
    private function calcularPreco(ProdutoAuditoria $produto, float $preco): array
    {
        $tabela = (float) $produto->preco_tabela;
        $desconto = $produto->desconto_maximo_pct !== null ? (float) $produto->desconto_maximo_pct : null;
        $preco = round($preco, 2);

        return [
            'preco_tabela' => $tabela,
            'desconto_maximo_pct' => $desconto,
            'preco' => $preco,
            'requer_autorizacao' => $preco < PedidoVendaItem::precoMinimo($tabela, $desconto),
        ];
    }

    /** Reaplica o preço atual do catálogo nos itens; devolve quantos mudaram de snapshot. */
    private function revalidarItens(PedidoVenda $pedido): int
    {
        $alterados = 0;

        foreach ($pedido->itens()->with('produto')->get() as $item) {
            $produto = $item->produto;

            if (! $produto || ! $produto->ativo || $produto->preco_tabela === null) {
                throw ValidationException::withMessages(['itens' => sprintf(
                    '"%s" não está mais disponível para venda (inativo ou sem preço) — remova o item e envie de novo.',
                    $produto?->descricao ?? 'Produto',
                )]);
            }

            $novo = $this->calcularPreco($produto, (float) $item->preco);
            $mudou = (float) $item->preco_tabela !== $novo['preco_tabela']
                || ($item->desconto_maximo_pct !== null ? (float) $item->desconto_maximo_pct : null) !== $novo['desconto_maximo_pct'];

            if ($mudou || $item->requer_autorizacao !== $novo['requer_autorizacao']) {
                $item->update($novo);
                $alterados += $mudou ? 1 : 0;
            }
        }

        return $alterados;
    }

    /** O que valia no momento da solicitação/aprovação, por item (§6). */
    private function snapshot(PedidoVenda $pedido): array
    {
        return [
            'total' => round($pedido->itens->sum(fn (PedidoVendaItem $i) => $i->subtotal()), 2),
            'itens' => $pedido->itens->map(fn (PedidoVendaItem $i) => [
                'produto_id' => $i->produto?->uuid,
                'descricao' => $i->produto?->descricao,
                'quantidade' => (float) $i->quantidade,
                'preco_tabela' => (float) $i->preco_tabela,
                'desconto_maximo_pct' => $i->desconto_maximo_pct !== null ? (float) $i->desconto_maximo_pct : null,
                'preco_minimo' => $i->precoMinimoCalculado(),
                'preco' => (float) $i->preco,
                'requer_autorizacao' => $i->requer_autorizacao,
            ])->values()->all(),
        ];
    }

    private function registrarHistorico(
        Usuario $usuario,
        PedidoVenda $pedido,
        AcaoHistoricoPedidoVenda $acao,
        string $descricao,
        ?string $motivo = null,
        ?array $snapshot = null,
    ): void {
        PedidoVendaHistorico::create([
            'pedido_venda_id' => $pedido->id,
            'usuario_id' => $usuario->id,
            'acao' => $acao,
            'descricao' => mb_substr($descricao, 0, 255),
            'motivo' => $motivo,
            'snapshot' => $snapshot,
        ]);
    }

    /** KPIs do topo da lista — dentro do que o usuário enxerga. */
    private function resumo(Usuario $usuario): array
    {
        $concluidosMes = $this->visiveis($usuario)
            ->where('status', StatusPedidoVenda::CONCLUIDO)
            ->where('concluido_em', '>=', now()->startOfMonth());

        $valorMes = PedidoVendaItem::query()
            ->whereIn('pedido_venda_id', (clone $concluidosMes)->select('id'))
            ->selectRaw('COALESCE(SUM(quantidade * preco), 0) as total')
            ->value('total');

        return [
            'pendentes_autorizacao' => $this->visiveis($usuario)->where('status', StatusPedidoVenda::PENDENTE_AUTORIZACAO)->count(),
            'rascunhos' => $this->visiveis($usuario)->where('status', StatusPedidoVenda::RASCUNHO)->count(),
            'aprovados' => $this->visiveis($usuario)->where('status', StatusPedidoVenda::APROVADO)->count(),
            'concluidos_mes' => $concluidosMes->count(),
            'valor_concluido_mes' => round((float) $valorMes, 2),
        ];
    }
}

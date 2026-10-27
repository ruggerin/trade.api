<?php

namespace App\Support;

use App\Enums\StatusFaturamento;
use App\Models\Empresa;
use App\Models\Pedido;
use App\Models\PedidoEntrega;
use App\Models\PontoVenda;
use App\Models\ProdutoAuditoria;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Pedidos do ERP com valor, faturamento e entregas (docs/28 §4.2, docs/54, docs/61). Uma regra só
 * pra rota do integrador antigo (`POST /pedidos`, com usuário de serviço) e pra API de integração
 * (`PUT /integracao/v1/pedidos`, em lote, docs/96).
 *
 * - Idempotente por (empresa, numero_pedido): reenviar substitui cabeçalho e itens, nunca duplica.
 *   As entregas não são tocadas aqui.
 * - Valor e faturamento sempre gravados, com o parâmetro ligado ou não: só a exposição é controlada
 *   (Faturamento::podeVer), então nada se perde se a empresa ligar depois.
 * - Previsão de entrega que aparece ou muda avisa o promotor ("pedido a caminho"), se ainda não
 *   houve entrega.
 */
class PedidosErp
{
    /** Pedidos por chamada da API de integração (cada um com até 500 itens). */
    public const MAX_PEDIDOS = 100;

    public const MAX_ENTREGAS = 500;

    /** Regras do pedido sem a loja (a rota antiga usa uuid; a integração, o código do ERP). */
    public static function regras(): array
    {
        return [
            'numero_pedido' => ['required', 'string', 'max:100'],
            'numero_nf' => ['nullable', 'string', 'max:100'],
            'data_pedido' => ['required', 'date'],
            // Previsão de chegada na loja (docs/54 §4): reenviar com outra data = revisão da previsão.
            'data_previsao_entrega' => ['nullable', 'date'],
            'observacao' => ['nullable', 'string', 'max:2000'],
            'itens' => ['required', 'array', 'min:1', 'max:500'],
            'itens.*.codigo_externo_produto' => ['required', 'string', 'max:100'],
            'itens.*.descricao_produto' => ['required', 'string', 'max:255'],
            'itens.*.quantidade' => ['required', 'numeric', 'gt:0'],
            // Valor e faturamento (docs/61 §5.1), opcionais. Devolução chega NEGATIVA (Winthor), por
            // isso sem `min`; limites = o que as colunas decimal(14,2)/(14,4) aguentam.
            'valor_total' => ['nullable', 'numeric', 'between:-999999999999.99,999999999999.99'],
            'valor_lucro_total' => ['nullable', 'numeric', 'between:-999999999999.99,999999999999.99'],
            'status_faturamento' => ['nullable', Rule::enum(StatusFaturamento::class)],
            'faturado_em' => ['nullable', 'date'],
            'itens.*.preco_unitario' => ['nullable', 'numeric', 'between:-9999999999.9999,9999999999.9999'],
            'itens.*.subtotal' => ['nullable', 'numeric', 'between:-999999999999.99,999999999999.99'],
            'itens.*.custo_unitario' => ['nullable', 'numeric', 'between:-9999999999.9999,9999999999.9999'],
            'itens.*.valor_lucro' => ['nullable', 'numeric', 'between:-999999999999.99,999999999999.99'],
        ];
    }

    /**
     * Grava (cria ou substitui) o pedido. `$dados` já validado pelas regras acima.
     *
     * @return array{0: Pedido, 1: bool} o pedido e se foi criado agora
     */
    public static function gravar(Empresa $empresa, int $pontoVendaId, array $dados): array
    {
        $previsaoAnterior = null;
        $pedido = DB::transaction(function () use ($dados, $empresa, $pontoVendaId, &$previsaoAnterior) {
            $pedido = Pedido::withoutGlobalScopes()->where('empresa_id', $empresa->id)->where('numero_pedido', $dados['numero_pedido'])->first();
            $previsaoAnterior = $pedido?->data_previsao_entrega?->toDateString();
            $valores = ValoresDoPedido::calcular($dados);
            $atributos = $valores['pedido'] + [
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
                $pedido = Pedido::create(['empresa_id' => $empresa->id, 'numero_pedido' => $dados['numero_pedido']] + $atributos);
            }

            // Vínculo com o catálogo pelo código externo; sem match o item fica com a descrição crua
            // do ERP (produto_id null) em vez de derrubar o pedido inteiro.
            $codigos = collect($dados['itens'])->pluck('codigo_externo_produto')->unique();
            $produtos = ProdutoAuditoria::withoutGlobalScopes()->where('empresa_id', $empresa->id)->whereIn('codigo_externo', $codigos)->pluck('id', 'codigo_externo');

            foreach ($dados['itens'] as $i => $item) {
                $pedido->itens()->create($valores['itens'][$i] + [
                    'produto_id' => $produtos[$item['codigo_externo_produto']] ?? null,
                    'codigo_externo_produto' => $item['codigo_externo_produto'],
                    'descricao_produto' => $item['descricao_produto'],
                    'quantidade' => $item['quantidade'],
                ]);
            }

            return $pedido;
        });

        $pedido->load(['itens.produto', 'entregas', 'pontoVenda']);

        $previsaoNova = $pedido->data_previsao_entrega?->toDateString();
        if ($previsaoNova !== null && $previsaoNova !== $previsaoAnterior && $pedido->entregas->isEmpty()) {
            NotificacoesPedido::avisar($pedido, NotificacoesPedido::PREVISTO);
        }

        return [$pedido, $pedido->wasRecentlyCreated];
    }

    /**
     * Idempotente por (pedido, data_entrega): o mesmo horário reenviado não duplica a entrega nem
     * avisa de novo.
     *
     * @return array{0: PedidoEntrega, 1: bool}
     */
    public static function entregar(Pedido $pedido, string $dataEntrega, ?string $observacao): array
    {
        $entrega = $pedido->entregas()->firstOrCreate(['data_entrega' => $dataEntrega], ['observacao' => $observacao]);

        if ($entrega->wasRecentlyCreated) {
            NotificacoesPedido::avisar($pedido, NotificacoesPedido::ENTREGUE);
        }

        return [$entrega, $entrega->wasRecentlyCreated];
    }

    // ── Lotes da API de integração (contrato do LoteIntegracao) ────────────────────────────────

    public static function aplicarPedidos(Empresa $empresa, array $linhas, bool $simular): array
    {
        $lojas = PontoVenda::withoutGlobalScopes()->where('empresa_id', $empresa->id)
            ->whereIn('codigo_externo', array_filter(array_map(fn ($l) => is_string($l['dados']['codigo_externo_loja'] ?? null) ? $l['dados']['codigo_externo_loja'] : null, $linhas)))
            ->get(['id', 'codigo_externo'])->groupBy('codigo_externo');

        $erros = [];
        $validos = [];
        $vistos = [];
        foreach ($linhas as ['numero' => $numero, 'dados' => $dados]) {
            $numeroPedido = is_scalar($dados['numero_pedido'] ?? null) ? (string) $dados['numero_pedido'] : null;
            $validador = Validator::make($dados, ['codigo_externo_loja' => ['required', 'string', 'max:64'], ...self::regras()]);
            $mensagens = $validador->errors()->all();
            $loja = isset($dados['codigo_externo_loja']) && is_string($dados['codigo_externo_loja']) ? $lojas->get($dados['codigo_externo_loja']) : null;
            if (! $mensagens && ! $loja) {
                $mensagens[] = "Loja {$dados['codigo_externo_loja']} não cadastrada: envie as lojas antes.";
            } elseif ($loja && $loja->count() > 1) {
                $mensagens[] = 'Existe mais de uma loja com este código externo: corrija no cadastro.';
            }
            if ($numeroPedido !== null && isset($vistos[$numeroPedido])) {
                $mensagens[] = "Pedido repetido no lote (item {$vistos[$numeroPedido]}).";
            }
            if ($mensagens) {
                $erros[] = ['linha' => $numero, 'codigo_externo' => $numeroPedido, 'mensagem' => implode(' ', $mensagens)];

                continue;
            }
            $vistos[$numeroPedido] = $numero;
            $validos[] = ['loja' => $loja->first()->id, 'dados' => $validador->validated()];
        }

        $relatorio = ['total' => count($linhas), 'erros' => $erros, 'aplicado' => false];
        if ($simular || ! $validos) {
            return $relatorio;
        }

        foreach ($validos as $v) {
            self::gravar($empresa, $v['loja'], $v['dados']);
        }

        return ['aplicado' => true] + $relatorio;
    }

    public static function aplicarEntregas(Empresa $empresa, array $linhas, bool $simular): array
    {
        $numeros = array_filter(array_map(fn ($l) => is_scalar($l['dados']['numero_pedido'] ?? null) ? (string) $l['dados']['numero_pedido'] : null, $linhas));
        $pedidos = Pedido::withoutGlobalScopes()->where('empresa_id', $empresa->id)->whereIn('numero_pedido', $numeros)->get()->keyBy('numero_pedido');

        $erros = [];
        $validos = [];
        foreach ($linhas as ['numero' => $numero, 'dados' => $dados]) {
            $validador = Validator::make($dados, [
                'numero_pedido' => ['required', 'string', 'max:100'],
                'data_entrega' => ['required', 'date'],
                'observacao' => ['nullable', 'string', 'max:2000'],
            ]);
            $mensagens = $validador->errors()->all();
            $pedido = ! $mensagens ? $pedidos->get($dados['numero_pedido']) : null;
            if (! $mensagens && ! $pedido) {
                $mensagens[] = "Pedido {$dados['numero_pedido']} não encontrado: envie o pedido antes da entrega.";
            }
            if ($mensagens) {
                $erros[] = ['linha' => $numero, 'codigo_externo' => isset($dados['numero_pedido']) && is_scalar($dados['numero_pedido']) ? (string) $dados['numero_pedido'] : null, 'mensagem' => implode(' ', $mensagens)];

                continue;
            }
            $validos[] = ['pedido' => $pedido, 'dados' => $validador->validated()];
        }

        $relatorio = ['total' => count($linhas), 'erros' => $erros, 'aplicado' => false];
        if ($simular || ! $validos) {
            return $relatorio;
        }

        foreach ($validos as $v) {
            self::entregar($v['pedido'], $v['dados']['data_entrega'], $v['dados']['observacao'] ?? null);
        }

        return ['aplicado' => true] + $relatorio;
    }

    /** @return array<string, true> */
    public static function pedidosExistentes(Empresa $empresa, array $numeros): array
    {
        return Pedido::withoutGlobalScopes()->where('empresa_id', $empresa->id)->whereIn('numero_pedido', $numeros)
            ->pluck('numero_pedido')->mapWithKeys(fn ($n) => [$n => true])->all();
    }

    /**
     * Entrega "atualizada" = mesmo pedido e mesmo horário já registrados (reenvio idempotente).
     *
     * @return array<string, true>
     */
    public static function entregasExistentes(Empresa $empresa, array $itens): array
    {
        $existentes = [];
        foreach ($itens as $chave) {
            [$numero, $data] = explode('@', $chave, 2) + [1 => null];
            try {
                $quando = $data !== null ? \Illuminate\Support\Carbon::parse($data) : null;
            } catch (\Throwable) {
                $quando = null;
            }
            if ($quando === null) {
                continue;
            }
            $existe = DB::table('pedido_entregas as e')
                ->join('pedidos as p', 'p.id', '=', 'e.pedido_id')
                ->where('p.empresa_id', $empresa->id)
                ->where('p.numero_pedido', $numero)
                ->where('e.data_entrega', $quando)
                ->exists();
            if ($existe) {
                $existentes[$chave] = true;
            }
        }

        return $existentes;
    }
}

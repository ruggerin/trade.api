<?php

namespace App\Support;

use App\Enums\StatusFaturamento;

/**
 * Regras de preenchimento do valor e do faturamento de um pedido do ERP (docs/61 §5.1). Isolado do
 * controller pra ser testado sem HTTP: recebe o payload já validado e devolve o que gravar.
 *
 * - Subtotal vem do ERP e NÃO é recalculado (o ERP é a fonte da verdade); só é preenchido por
 *   preço × quantidade quando o ERP não mandou.
 * - Custo e lucro: mandou os dois, grava como veio e marca divergência se não baterem; mandou um,
 *   o outro é derivado e marcado CALCULADO; nenhum, ficam nulos ("sem informação", nunca zero).
 * - Total do pedido: o que o ERP mandou; senão a soma dos itens quando TODOS têm valor. Quando vêm
 *   os dois e divergem, sinaliza em vez de escolher em silêncio.
 * - Status: o do ERP; senão `DEVOLVIDO` se o total é negativo (Winthor emite devolução com valor
 *   negativo); senão `FATURADO` se tem NF e data de faturamento; senão nulo.
 */
final class ValoresDoPedido
{
    private const TOLERANCIA = 0.01;

    /**
     * @param  array<string, mixed>  $dados  payload validado de StorePedidoRequest
     * @return array{pedido: array<string, mixed>, itens: list<array<string, mixed>>}
     */
    public static function calcular(array $dados): array
    {
        $itens = [];
        $subtotais = [];
        $lucros = [];

        foreach ($dados['itens'] as $item) {
            $calculado = self::item($item);
            $itens[] = $calculado;
            $subtotais[] = $calculado['subtotal'];
            $lucros[] = $calculado['valor_lucro'];
        }

        $somaSubtotais = self::somaSeCompleta($subtotais);
        $somaLucros = self::somaSeCompleta($lucros);

        $valorTotal = self::numero($dados['valor_total'] ?? null) ?? $somaSubtotais;
        $lucroTotal = self::numero($dados['valor_lucro_total'] ?? null) ?? $somaLucros;

        $divergente = false;
        if (isset($dados['valor_total']) && $somaSubtotais !== null && abs($somaSubtotais - (float) $dados['valor_total']) > self::TOLERANCIA) {
            $divergente = true;
        }
        if (isset($dados['valor_lucro_total']) && $somaLucros !== null && abs($somaLucros - (float) $dados['valor_lucro_total']) > self::TOLERANCIA) {
            $divergente = true;
        }

        return [
            'pedido' => [
                'valor_total' => $valorTotal,
                'valor_lucro_total' => $lucroTotal,
                'status_faturamento' => self::status($dados, $valorTotal),
                'faturado_em' => $dados['faturado_em'] ?? null,
                'valor_divergente' => $divergente,
            ],
            'itens' => $itens,
        ];
    }

    /** @param array<string, mixed> $item */
    private static function item(array $item): array
    {
        $quantidade = (float) $item['quantidade'];
        $preco = self::numero($item['preco_unitario'] ?? null);
        $subtotal = self::numero($item['subtotal'] ?? null) ?? ($preco !== null ? round($preco * $quantidade, 2) : null);
        $custo = self::numero($item['custo_unitario'] ?? null);
        $lucro = self::numero($item['valor_lucro'] ?? null);

        $origem = null;
        $divergente = false;

        if ($custo !== null && $lucro !== null) {
            $origem = 'ERP';
            // Subtotal − custo × quantidade deveria dar o lucro.
            $divergente = $subtotal !== null && abs($subtotal - $custo * $quantidade - $lucro) > self::TOLERANCIA;
        } elseif ($custo !== null && $subtotal !== null) {
            $lucro = round($subtotal - $custo * $quantidade, 2);
            $origem = 'CALCULADO';
        } elseif ($lucro !== null && $subtotal !== null && $quantidade > 0) {
            $custo = round(($subtotal - $lucro) / $quantidade, 4);
            $origem = 'CALCULADO';
        } elseif ($custo !== null || $lucro !== null) {
            // Só um dos dois e sem subtotal pra completar: grava o que veio, origem ERP.
            $origem = 'ERP';
        }

        return [
            'preco_unitario' => $preco,
            'subtotal' => $subtotal,
            'custo_unitario' => $custo,
            'valor_lucro' => $lucro,
            'origem_lucro' => $origem,
            'valor_divergente' => $divergente,
        ];
    }

    /** @param array<string, mixed> $dados */
    private static function status(array $dados, ?float $valorTotal): ?string
    {
        if (! empty($dados['status_faturamento'])) {
            $s = $dados['status_faturamento'];

            return $s instanceof StatusFaturamento ? $s->value : (string) $s;
        }
        if ($valorTotal !== null && $valorTotal < 0) {
            return StatusFaturamento::DEVOLVIDO->value;
        }
        if (! empty($dados['numero_nf']) && ! empty($dados['faturado_em'])) {
            return StatusFaturamento::FATURADO->value;
        }

        return null;
    }

    /** @param list<?float> $valores */
    private static function somaSeCompleta(array $valores): ?float
    {
        if ($valores === [] || in_array(null, $valores, true)) {
            return null;
        }

        return round(array_sum($valores), 2);
    }

    private static function numero(mixed $valor): ?float
    {
        return $valor === null || $valor === '' ? null : (float) $valor;
    }
}

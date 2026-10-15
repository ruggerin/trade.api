<?php

namespace App\Support;

use App\Enums\StatusFaturamento;
use App\Models\Pedido;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * "Esforço × Retorno" (docs/61 §6): cruza o tempo efetivo na loja (já calculado pelo relatório
 * "Tempo na loja") com o faturamento dos pedidos do ERP — mesmo recorte, mesmas linhas.
 *
 * Só é chamado quando Faturamento::podeVer(). O sistema entrega o número; a leitura é de quem usa
 * (sem veredito de "loja boa/ruim").
 *
 * Regras:
 * - Período do pedido é `faturado_em` (a venda cai no período em que foi faturada).
 * - Faturamento = pedidos das lojas **visitadas** no período e filtros do relatório. Loja que
 *   faturou sem nenhuma visita não aparece (limitação da v1, documentada no doc 61).
 * - Bruto = FATURADO; devoluções = DEVOLVIDO (em módulo); líquido = bruto − devoluções. Pedido
 *   CANCELADO e NAO_FATURADO ficam de fora. Status ausente: positivo conta como faturado e
 *   negativo como devolução (o `faturado_em` preenchido já diz que foi faturado).
 * - Sem pedido com valor no recorte, tudo é `null` ("sem informação"), nunca zero.
 * - Por promotor não há atribuição de faturamento (o pedido é da loja, não de quem visitou).
 */
final class EsforcoRetorno
{
    /**
     * @param  Collection<int, array<string, mixed>>  $linhas
     * @param  array<string, mixed>  $total
     * @param  array<string, list<int>>  $lojasPorGrupo  chave do grupo → ids das lojas visitadas nele
     * @return array{linhas: Collection<int, array<string, mixed>>, total: array<string, mixed>, faturamento: array<string, mixed>}
     */
    public static function anexar(Collection $linhas, array $total, array $lojasPorGrupo, string $agrupar, Carbon $inicio, Carbon $fim, string $tz): array
    {
        $de = $inicio->copy()->setTimezone($tz)->toDateString();
        $ate = $fim->copy()->setTimezone($tz)->toDateString();
        $meta = ['habilitado' => true, 'periodo' => ['data_inicio' => $de, 'data_fim' => $ate], 'atribuido_por' => $agrupar];

        if ($agrupar === 'promotor') {
            return [
                'linhas' => $linhas,
                'total' => $total,
                'faturamento' => $meta + ['nota' => 'O faturamento é da loja; não é atribuído por promotor.'],
            ];
        }

        $todasAsLojas = [];
        foreach ($lojasPorGrupo as $ids) {
            $todasAsLojas = array_merge($todasAsLojas, $ids);
        }
        $todasAsLojas = array_values(array_unique($todasAsLojas));

        $pedidos = $todasAsLojas === []
            ? collect()
            : Pedido::query()
                ->whereIn('ponto_venda_id', $todasAsLojas)
                ->whereBetween('faturado_em', [$de, $ate])
                ->whereNotNull('valor_total')
                ->get(['ponto_venda_id', 'faturado_em', 'status_faturamento', 'valor_total', 'valor_lucro_total']);

        $linhas = $linhas->map(function (array $linha) use ($pedidos, $lojasPorGrupo, $agrupar) {
            $lojas = $lojasPorGrupo[$linha['chave']] ?? [];
            $doGrupo = match ($agrupar) {
                // O dia é o dia do faturamento; as lojas são as visitadas no período.
                'dia' => $pedidos->filter(fn (Pedido $p) => $p->faturado_em?->toDateString() === $linha['chave']),
                default => $pedidos->filter(fn (Pedido $p) => in_array($p->ponto_venda_id, $lojas, true)),
            };

            return $linha + self::resumir($doGrupo, (int) ($linha['tempo_total_minutos'] ?? 0));
        })->values();

        return [
            'linhas' => $linhas,
            'total' => $total + self::resumir($pedidos, (int) ($total['tempo_total_minutos'] ?? 0)),
            'faturamento' => $meta,
        ];
    }

    /**
     * @param  Collection<int, Pedido>  $pedidos
     * @return array<string, mixed>
     */
    public static function resumir(Collection $pedidos, int $minutosNaLoja): array
    {
        $vazio = [
            'faturamento_bruto' => null, 'devolucoes' => null, 'faturamento_liquido' => null,
            'lucro' => null, 'margem_pct' => null, 'faturamento_por_hora' => null, 'lucro_por_hora' => null,
            'pedidos_faturados' => 0,
        ];

        $validos = $pedidos->filter(fn (Pedido $p) => $p->valor_total !== null
            && ! in_array($p->status_faturamento, [StatusFaturamento::CANCELADO->value, StatusFaturamento::NAO_FATURADO->value], true));
        if ($validos->isEmpty()) {
            return $vazio;
        }

        $ehDevolucao = fn (Pedido $p) => $p->status_faturamento === StatusFaturamento::DEVOLVIDO->value
            || ($p->status_faturamento === null && $p->valor_total < 0);

        $bruto = round($validos->reject($ehDevolucao)->sum('valor_total'), 2);
        $devolucoes = round(abs($validos->filter($ehDevolucao)->sum('valor_total')), 2);
        $liquido = round($bruto - $devolucoes, 2);

        $comLucro = $validos->filter(fn (Pedido $p) => $p->valor_lucro_total !== null);
        $lucro = $comLucro->isEmpty() ? null : round($comLucro->sum('valor_lucro_total'), 2);

        $horas = $minutosNaLoja / 60;

        return [
            'faturamento_bruto' => $bruto,
            'devolucoes' => $devolucoes,
            'faturamento_liquido' => $liquido,
            'lucro' => $lucro,
            // Margem sobre o faturamento líquido; sem lucro informado ou líquido zero, sem informação.
            'margem_pct' => $lucro !== null && $liquido > 0 ? round($lucro / $liquido * 100, 1) : null,
            'faturamento_por_hora' => $horas > 0 ? round($liquido / $horas, 2) : null,
            'lucro_por_hora' => $lucro !== null && $horas > 0 ? round($lucro / $horas, 2) : null,
            'pedidos_faturados' => $validos->count(),
        ];
    }
}

<?php

namespace App\Support;

use App\Enums\StatusOrdemServico;
use App\Enums\StatusVisita;
use App\Models\OrdemServico;
use App\Models\Visita;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * As lojas por trás do relatório Visitas planejadas × executadas (docs/59 §4): o relatório soma
 * "dia × promotor"; aqui sai a loja de cada número, pra abrir a linha (quais lojas o promotor
 * visitou ou não naquele dia) e pra visão por loja do período.
 *
 * A classificação é a MESMA do RelatorioController::calcularVisitasPlanejadas, pra lista e número
 * baterem sempre: OS concluída = visitada; em andamento; prazo vencido sem visita = não visitada
 * (a coluna "atrasadas"); prazo ainda aberto = a vencer; cancelada à parte; visita sem OS =
 * espontânea. O dia é o do prazo final da OS (da entrada, na espontânea), no fuso do relatório, e o
 * promotor é quem executou, senão a quem a OS foi direcionada.
 */
class LojasDoPlanejamento
{
    public const VISITADA = 'visitada';

    public const EM_ANDAMENTO = 'em_andamento';

    public const NAO_VISITADA = 'nao_visitada';

    public const A_VENCER = 'a_vencer';

    public const CANCELADA = 'cancelada';

    public const ESPONTANEA = 'espontanea';

    /**
     * @param  bool|null  $semPromotor  true = só as OS que ninguém pegou (linha "—" do relatório)
     * @return Collection<int, array<string, mixed>>
     */
    public static function itens(Carbon $inicio, Carbon $fim, string $tz, ?int $usuarioId, ?int $pontoVendaId, bool $semPromotor = false): Collection
    {
        $agora = now();
        $camposVisita = 'id,uuid,usuario_id,status,inicio_data,fim_data,afastamento_minutos';

        $ordens = OrdemServico::query()
            ->with([
                'pontoVenda:id,uuid,fantasia,codigo_externo,latitude,longitude',
                'usuario:id,uuid,nome',
                'visita' => fn ($q) => $q->select(explode(',', $camposVisita))->withCount('registros'),
                'visita.usuario:id,uuid,nome',
                'motivoCancelamento:id,descricao',
            ])
            ->whereBetween('prazo_fim', [$inicio, $fim])
            ->where('status', '!=', StatusOrdemServico::AGUARDANDO_APROVACAO->value)
            ->when($pontoVendaId, fn ($q) => $q->where('ponto_venda_id', $pontoVendaId))
            ->when($usuarioId, fn ($q) => $q->where(
                fn ($sub) => $sub->where('usuario_id', $usuarioId)
                    ->orWhereHas('visita', fn ($v) => $v->where('usuario_id', $usuarioId)),
            ))
            ->get();

        $espontaneas = $semPromotor ? collect() : Visita::query()
            ->with(['pontoVenda:id,uuid,fantasia,codigo_externo,latitude,longitude', 'usuario:id,uuid,nome'])
            ->withCount('registros')
            ->whereNull('ordem_servico_id')
            ->where('status', '!=', StatusVisita::CANCELADA->value)
            ->whereBetween('inicio_data', [$inicio, $fim])
            ->when($pontoVendaId, fn ($q) => $q->where('ponto_venda_id', $pontoVendaId))
            ->when($usuarioId, fn ($q) => $q->where('usuario_id', $usuarioId))
            ->get();

        $itens = collect();

        foreach ($ordens as $os) {
            $promotor = $os->visita?->usuario ?? $os->usuario;
            // Filtro de promotor: o relatório atribui a linha a quem executou; OS direcionada a ele
            // mas feita por outro não é dele.
            if ($usuarioId && $promotor?->id !== $usuarioId) {
                continue;
            }
            if ($semPromotor && $promotor !== null) {
                continue;
            }

            $situacao = match (true) {
                $os->status === StatusOrdemServico::CANCELADA => self::CANCELADA,
                $os->status === StatusOrdemServico::CONCLUIDA => self::VISITADA,
                $os->status === StatusOrdemServico::EM_ANDAMENTO => self::EM_ANDAMENTO,
                $os->prazo_fim->lt($agora) => self::NAO_VISITADA,
                default => self::A_VENCER,
            };

            $itens->push(self::item(
                $os->prazo_fim->copy()->setTimezone($tz)->toDateString(),
                $promotor,
                $os->pontoVenda,
                $situacao,
                $os->visita,
                $tz,
                [
                    'ordem_servico_id' => $os->uuid,
                    'previsto' => $os->horario_previsto ? substr((string) $os->horario_previsto, 0, 5) : null,
                    'prazo_fim' => $os->prazo_fim->copy()->setTimezone($tz)->format('H:i'),
                    'obrigatoria' => (bool) $os->obrigatoria,
                    'motivo_cancelamento' => $situacao === self::CANCELADA
                        ? ($os->motivoCancelamento?->descricao ?? $os->motivo_cancelamento_texto)
                        : null,
                    'responsavel_cancelamento' => $situacao === self::CANCELADA ? $os->responsavel_nao_execucao : null,
                ],
            ));
        }

        foreach ($espontaneas as $visita) {
            $itens->push(self::item(
                $visita->inicio_data->copy()->setTimezone($tz)->toDateString(),
                $visita->usuario,
                $visita->pontoVenda,
                self::ESPONTANEA,
                $visita,
                $tz,
                ['ordem_servico_id' => null, 'previsto' => null, 'prazo_fim' => null, 'obrigatoria' => false, 'motivo_cancelamento' => null, 'responsavel_cancelamento' => null],
            ));
        }

        // Ordem do dia: pelo horário previsto (ou da entrada), as sem horário no fim.
        return $itens->sortBy(fn ($i) => [$i['data'], $i['promotor']['nome'] ?? '', $i['previsto'] ?? $i['entrada'] ?? '99:99', $i['loja']['nome'] ?? ''])->values();
    }

    /**
     * Visão por loja do período: uma linha por loja, com o que foi planejado, visitado e perdido, e
     * em quais dias a visita foi perdida. Ordem: mais visitas perdidas primeiro.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public static function porLoja(Collection $itens, string $tz, bool $soPerdidas): Collection
    {
        $lojas = $itens->filter(fn ($i) => $i['loja'] !== null)->groupBy(fn ($i) => $i['loja']['id']);

        // Última visita da loja, de qualquer promotor e de antes do período também: "há 14 dias" vale
        // mais que "nenhuma no período" pra quem decide.
        $ultimas = Visita::query()
            ->join('pontos_venda as pv', 'pv.id', '=', 'visitas.ponto_venda_id')
            ->whereIn('pv.uuid', $lojas->keys())
            ->where('visitas.status', StatusVisita::FINALIZADA->value)
            ->groupBy('pv.uuid')
            ->selectRaw('pv.uuid as loja, max(visitas.inicio_data) as ultima')
            ->pluck('ultima', 'loja');

        return $lojas->map(function (Collection $doLoja, string $uuid) use ($ultimas, $tz) {
            $conta = fn (string $s) => $doLoja->where('situacao', $s)->count();
            $ultima = isset($ultimas[$uuid]) ? Carbon::parse($ultimas[$uuid], 'UTC')->setTimezone($tz) : null;

            return [
                'loja' => $doLoja->first()['loja'],
                'promotores' => $doLoja->pluck('promotor')->filter()->unique('id')->values(),
                'planejadas' => $doLoja->whereNotIn('situacao', [self::CANCELADA, self::ESPONTANEA])->count(),
                'visitadas' => $conta(self::VISITADA),
                'em_andamento' => $conta(self::EM_ANDAMENTO),
                'nao_visitadas' => $conta(self::NAO_VISITADA),
                'a_vencer' => $conta(self::A_VENCER),
                'canceladas' => $conta(self::CANCELADA),
                'espontaneas' => $conta(self::ESPONTANEA),
                'dias_perdidos' => $doLoja->where('situacao', self::NAO_VISITADA)->pluck('data')->unique()->sort()->values(),
                'ultima_visita' => $ultima?->toIso8601String(),
                'dias_sem_visita' => $ultima ? (int) $ultima->copy()->startOfDay()->diffInDays(now($tz)->startOfDay()) : null,
            ];
        })
            ->when($soPerdidas, fn ($c) => $c->filter(fn ($l) => $l['nao_visitadas'] > 0))
            ->sortBy([['nao_visitadas', 'desc'], ['planejadas', 'desc'], fn ($a, $b) => strcmp($a['loja']['nome'] ?? '', $b['loja']['nome'] ?? '')])
            ->values();
    }

    private static function item(string $data, $promotor, $loja, string $situacao, ?Visita $visita, string $tz, array $extra): array
    {
        $entrada = $visita?->inicio_data?->copy()->setTimezone($tz);
        $saida = $visita?->fim_data?->copy()->setTimezone($tz);

        return [
            'data' => $data,
            'promotor' => $promotor ? ['id' => $promotor->uuid, 'nome' => $promotor->nome] : null,
            'loja' => $loja ? ['id' => $loja->uuid, 'nome' => $loja->fantasia, 'codigo_externo' => $loja->codigo_externo, 'latitude' => $loja->latitude, 'longitude' => $loja->longitude] : null,
            'situacao' => $situacao,
            'visita_id' => $visita?->uuid,
            'entrada' => $entrada?->format('H:i'),
            'saida' => $saida?->format('H:i'),
            // Tempo na loja = saída − entrada − afastamento (a mesma conta do Tempo no PDV, docs/59).
            'minutos_na_loja' => $entrada && $saida ? max(0, (int) $entrada->diffInMinutes($saida) - (int) ($visita->afastamento_minutos ?? 0)) : null,
            'registros' => $visita?->registros_count,
        ] + $extra;
    }
}

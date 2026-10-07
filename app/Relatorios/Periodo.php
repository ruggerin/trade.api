<?php

namespace App\Relatorios;

use Carbon\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Período dos relatórios (docs/60 §3.1, §3.3): presets resolvidos no fuso da empresa — o relatório
 * salvo como "últimos 30 dias" continua valendo amanhã — e o período de comparação, com a mesma
 * regra dos relatórios fixos do doc 59 (RelatorioController delega pra cá).
 */
final class Periodo
{
    /** Um ano inteiro (o relatório fixo do doc 59 continua com 92). */
    public const MAX_DIAS = 366;

    public const PRESETS = [
        'hoje', 'ultimos_7_dias', 'ultimos_30_dias', 'mes_atual', 'mes_anterior',
        'trimestre_atual', 'ultimos_12_meses', 'ano_atual', 'ano_anterior',
    ];

    /** Níveis de data, no espírito da hierarquia do Power BI (+ os cíclicos mês do ano e dia da semana). */
    public const GRANULARIDADES = ['ano', 'trimestre', 'mes', 'semana', 'dia', 'mes_do_ano', 'dia_da_semana'];

    public const ROTULOS_GRANULARIDADE = [
        'ano' => 'Ano',
        'trimestre' => 'Trimestre',
        'mes' => 'Mês',
        'semana' => 'Semana',
        'dia' => 'Data',
        'mes_do_ano' => 'Mês do ano',
        'dia_da_semana' => 'Dia da semana',
    ];

    /** Ciclo (jan…dez, seg…dom): ordena crescente, não do mais recente pro mais antigo. */
    public const CICLICAS = ['mes_do_ano', 'dia_da_semana'];

    private const MESES = ['jan', 'fev', 'mar', 'abr', 'mai', 'jun', 'jul', 'ago', 'set', 'out', 'nov', 'dez'];

    private const MESES_LONGOS = ['Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho', 'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro'];

    private const DIAS_SEMANA = [1 => 'Segunda', 'Terça', 'Quarta', 'Quinta', 'Sexta', 'Sábado', 'Domingo'];

    public const COMPARACOES = ['anterior', 'ano_anterior'];

    /**
     * @param  array{preset?: ?string, inicio?: ?string, fim?: ?string}  $periodo
     * @return array{0: Carbon, 1: Carbon} início e fim em UTC
     */
    public static function resolver(array $periodo, string $tz): array
    {
        $hoje = now($tz)->startOfDay();

        if (! empty($periodo['inicio']) && ! empty($periodo['fim'])) {
            $inicio = Carbon::parse($periodo['inicio'], $tz)->startOfDay();
            $fim = Carbon::parse($periodo['fim'], $tz)->endOfDay();
        } else {
            [$inicio, $fim] = match ($periodo['preset'] ?? 'ultimos_7_dias') {
                'hoje' => [$hoje->copy(), $hoje->copy()->endOfDay()],
                'ultimos_30_dias' => [$hoje->copy()->subDays(29), $hoje->copy()->endOfDay()],
                'mes_atual' => [$hoje->copy()->startOfMonth(), $hoje->copy()->endOfDay()],
                'mes_anterior' => [$hoje->copy()->subMonthNoOverflow()->startOfMonth(), $hoje->copy()->subMonthNoOverflow()->endOfMonth()],
                'trimestre_atual' => [$hoje->copy()->startOfQuarter(), $hoje->copy()->endOfDay()],
                'ultimos_12_meses' => [$hoje->copy()->subMonthsNoOverflow(12)->addDay(), $hoje->copy()->endOfDay()],
                'ano_atual' => [$hoje->copy()->startOfYear(), $hoje->copy()->endOfDay()],
                'ano_anterior' => [$hoje->copy()->subYear()->startOfYear(), $hoje->copy()->subYear()->endOfYear()],
                default => [$hoje->copy()->subDays(6), $hoje->copy()->endOfDay()],
            };
        }

        if ($fim->lt($inicio)) {
            throw ValidationException::withMessages(['periodo' => 'O fim do período é anterior ao início.']);
        }
        if ($inicio->diffInDays($fim) > self::MAX_DIAS) {
            throw ValidationException::withMessages(['periodo' => 'O período máximo de um relatório é de '.self::MAX_DIAS.' dias.']);
        }

        return [$inicio->utc(), $fim->utc()];
    }

    /**
     * Período equivalente pra comparar: `anterior` = mesmo número de dias imediatamente antes;
     * `ano_anterior` = mesmas datas um ano antes. Devolvido em UTC.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public static function comparacao(Carbon $inicio, Carbon $fim, string $tz, string $tipo): array
    {
        $inicioLocal = $inicio->copy()->setTimezone($tz)->startOfDay();
        $fimLocal = $fim->copy()->setTimezone($tz)->endOfDay();

        if ($tipo === 'ano_anterior') {
            return [$inicioLocal->copy()->subYear()->startOfDay()->utc(), $fimLocal->copy()->subYear()->endOfDay()->utc()];
        }

        $dias = $inicioLocal->diffInDays($fimLocal->copy()->startOfDay()) + 1;
        $compFim = $inicioLocal->copy()->subDay()->endOfDay();
        $compInicio = $compFim->copy()->subDays($dias - 1)->startOfDay();

        return [$compInicio->utc(), $compFim->utc()];
    }

    /** @return array{data_inicio: string, data_fim: string} */
    public static function local(Carbon $inicio, Carbon $fim, string $tz): array
    {
        return ['data_inicio' => $inicio->copy()->setTimezone($tz)->toDateString(), 'data_fim' => $fim->copy()->setTimezone($tz)->toDateString()];
    }

    /**
     * Chave e rótulo de um instante agrupado por dia/semana/mês, no fuso informado.
     *
     * @return array{0: string, 1: string}
     */
    public static function agrupar(?Carbon $instante, ?string $granularidade, string $tz): array
    {
        if ($instante === null) {
            return ['-', 'Sem data'];
        }
        $local = $instante->copy()->setTimezone($tz);

        return match ($granularidade) {
            'ano' => [$local->format('Y'), $local->format('Y')],
            'trimestre' => [$local->format('Y').'-T'.$local->quarter, $local->quarter.'º tri/'.$local->format('Y')],
            'semana' => [$local->copy()->startOfWeek()->toDateString(), 'Semana de '.$local->copy()->startOfWeek()->format('d/m/Y')],
            'mes' => [$local->format('Y-m'), self::MESES[$local->month - 1].'/'.$local->format('Y')],
            'mes_do_ano' => [$local->format('m'), self::MESES_LONGOS[$local->month - 1]],
            'dia_da_semana' => [(string) $local->dayOfWeekIso, self::DIAS_SEMANA[$local->dayOfWeekIso]],
            default => [$local->toDateString(), $local->format('d/m/Y')],
        };
    }
}

<?php

namespace App\Support;

use App\Models\Empresa;
use App\Models\PontoVenda;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Support\Carbon;

/**
 * Fuso horário de uma operação — docs/50-SUPORTE-MULTIPLOS-FUSOS-HORARIOS.md §4. Três regras:
 *
 * - Duração ("há 15 min") não tem fuso: diferença entre dois instantes UTC (§4.1).
 * - Horário marcado é da LOJA (`pontos_venda.fuso`, herda da empresa quando nulo) (§4.2).
 * - Corte de "dia" é da EMPRESA (`empresas.fuso`) — um "hoje" único pro dashboard inteiro (§4.3).
 *
 * Tudo que sai daqui pra comparar com o banco vem em UTC, que é como o instante é gravado.
 */
final class Fuso
{
    public const PADRAO = 'America/Sao_Paulo';

    public static function daEmpresa(?Empresa $empresa): string
    {
        return self::valido($empresa?->fuso) ?? self::PADRAO;
    }

    /** Mesmo que daEmpresa(), a partir do id — pra model sem a relação `empresa` carregada. */
    public static function daEmpresaId(?int $empresaId): string
    {
        static $cache = [];

        return $cache[$empresaId ?? 0] ??= self::daEmpresa($empresaId ? Empresa::withoutGlobalScopes()->find($empresaId) : null);
    }

    /** O fuso da loja; sem um próprio, o da empresa dela. */
    public static function daLoja(PontoVenda $pontoVenda): string
    {
        return self::valido($pontoVenda->fuso)
            ?? self::daEmpresa($pontoVenda->empresa()->withoutGlobalScopes()->first());
    }

    /** "Hoje" no fuso informado, como data local (00:00 daquele fuso). */
    public static function hoje(string $fuso): Carbon
    {
        return now($fuso)->startOfDay();
    }

    /**
     * Início e fim de um dia local (`YYYY-MM-DD`) no fuso informado, já em UTC — pronto pra
     * `whereBetween` contra coluna de instante.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public static function intervaloDoDia(string $diaLocal, string $fuso): array
    {
        $dia = Carbon::parse($diaLocal, $fuso);

        return [$dia->copy()->startOfDay()->utc(), $dia->copy()->endOfDay()->utc()];
    }

    /**
     * Instante UTC de uma data (`DATE`) + hora (`TIME`, opcional — sem hora = 00:00) marcadas num
     * fuso — pra comparar um horário previsto contra "agora" (atraso, vencimento).
     */
    public static function instanteLocal(string $data, ?string $hora, string $fuso): Carbon
    {
        return Carbon::parse(trim(substr($data, 0, 10).' '.($hora ?? '00:00')), $fuso)->utc();
    }

    /**
     * Filtro "de/até" por data local (`YYYY-MM-DD`, como os seletores de período mandam) contra
     * uma coluna de instante — o dia vai da meia-noite à meia-noite do fuso, em UTC. Substitui o
     * `whereDate`, que cortava o dia na meia-noite UTC.
     */
    public static function filtrarPeriodo(Builder $query, string $coluna, ?string $de, ?string $ate, string $fuso): Builder
    {
        if ($de !== null && $de !== '') {
            $query->where($coluna, '>=', self::intervaloDoDia($de, $fuso)[0]);
        }
        if ($ate !== null && $ate !== '') {
            $query->where($coluna, '<=', self::intervaloDoDia($ate, $fuso)[1]);
        }

        return $query;
    }

    /** O dia de uma data de calendário (`DATE`, sem hora) já terminou no fuso informado? */
    public static function diaJaPassou(Carbon|string $data, string $fuso): bool
    {
        $dia = $data instanceof Carbon ? $data->toDateString() : substr($data, 0, 10);

        return self::intervaloDoDia($dia, $fuso)[1]->isPast();
    }

    private static function valido(?string $fuso): ?string
    {
        return $fuso !== null && in_array($fuso, \DateTimeZone::listIdentifiers(), true) ? $fuso : null;
    }
}

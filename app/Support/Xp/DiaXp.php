<?php

namespace App\Support\Xp;

use App\Enums\StatusOrdemServico;
use App\Models\Empresa;
use App\Models\OrdemServico;
use App\Support\Fuso;
use Illuminate\Support\Carbon;

/**
 * Em que dia uma OS conta na régua (docs/88 §6), no fuso da empresa (doc 50).
 *
 * - OS de um dia só (agenda): o próprio dia.
 * - OS de janela (campanha, direcionamento, vários dias): o dia do `prazo_fim` — a régua diária
 *   fica precisa e a semanal pega tudo.
 *
 * O possível do dia "congela" às 05:30 (logo depois da geração das 05:00, doc 10 §3.4): OS
 * cancelada antes disso sai do possível; cancelada depois continua e vira perda. OS criada depois
 * entra e o dia cresce (decisão de implementação, §15 item 4).
 */
final class DiaXp
{
    public const HORA_CONGELAMENTO = '05:30';

    public static function daOs(OrdemServico $os, string $fuso): string
    {
        $inicio = $os->prazo_inicio?->copy()->setTimezone($fuso)->toDateString();
        $fim = $os->prazo_fim?->copy()->setTimezone($fuso)->toDateString();

        return $fim ?? $inicio ?? now($fuso)->toDateString();
    }

    public static function local(?Carbon $instante, string $fuso): ?string
    {
        return $instante?->copy()->setTimezone($fuso)->toDateString();
    }

    /** Instante (UTC) em que o possível do dia congela. */
    public static function congelamento(string $dia, string $fuso): Carbon
    {
        return Fuso::instanteLocal($dia, self::HORA_CONGELAMENTO, $fuso);
    }

    /** A OS entra no possível do dia dela? */
    public static function contaNoPossivel(OrdemServico $os, string $fuso): bool
    {
        if ($os->xp_previsto === null || $os->usuario_id === null && $os->visita_id === null) {
            return false;
        }
        // Proposta do promotor ainda não aprovada não é plano.
        if ($os->status === StatusOrdemServico::AGUARDANDO_APROVACAO) {
            return false;
        }
        if ($os->status === StatusOrdemServico::CANCELADA && $os->cancelada_em
            && $os->cancelada_em->lt(self::congelamento(self::daOs($os, $fuso), $fuso))) {
            return false;
        }

        return true;
    }

    public static function fusoDa(Empresa $empresa): string
    {
        return Fuso::daEmpresa($empresa);
    }

    /** Segunda-feira (início da semana da liga) de um dia. */
    public static function inicioDaSemana(string $dia): string
    {
        return Carbon::parse($dia)->startOfWeek(Carbon::MONDAY)->toDateString();
    }
}

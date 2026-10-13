<?php

namespace App\Support;

use App\Enums\StatusOrdemServico;
use App\Models\OrdemServico;
use App\Models\PontoVenda;
use App\Models\Usuario;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Qual ordem de serviço a visita cumpre quando a loja tem mais de uma pendente pro promotor
 * (docs/07-ORDEM-DE-SERVICO.md §"OS do check-in"). Achado em produção em 13/10/2026: o app escolhia
 * pelo prazo que vence antes, então uma OS vencida de dias atrás "roubava" a visita de hoje — a
 * vencida virava concluída e a de hoje ficava pendente. É comum a empresa não cancelar as vencidas
 * (deixar "pra lá"), então isso aconteceria todo dia.
 *
 * Ordem, olhando o DIA DA VISITA no fuso da loja (não o dia em que o servidor recebe — check-in
 * offline enviado depois continua valendo pro dia em que aconteceu):
 *   1. vigente naquele dia (prazo_inicio ≤ dia ≤ prazo_fim) — formulário pendente primeiro, depois
 *      o prazo que vence antes (regra antiga do app, mantida dentro do dia);
 *   2. vencida — a mais recente (visita atrasada de verdade cumpre a última que ficou pra trás);
 *   3. futura — a mais próxima.
 *
 * A API só corrige a escolha do app quando ele mandou uma OS fora do dia e existe uma do dia: vale
 * na hora pra qualquer versão do app. A mesma ordem vive no app (escolherOrdemDaLoja).
 */
class OrdemDoCheckin
{
    private const VIGENTE = 0;

    private const VENCIDA = 1;

    private const FUTURA = 2;

    public static function escolher(OrdemServico $enviada, PontoVenda $pontoVenda, Usuario $promotor, CarbonInterface $instante): OrdemServico
    {
        $fuso = Fuso::daLoja($pontoVenda);
        $dia = $instante->copy()->setTimezone($fuso)->toDateString();

        if (self::grupo($enviada, $dia, $fuso) === self::VIGENTE) {
            return $enviada;
        }

        $melhor = self::ordenar(self::candidatas($pontoVenda, $promotor), $dia, $fuso)->first();

        return $melhor && self::grupo($melhor, $dia, $fuso) === self::VIGENTE ? $melhor : $enviada;
    }

    /** Pendentes da loja que o promotor pode assumir: as dele e as da fila aberta (sem promotor). */
    private static function candidatas(PontoVenda $pontoVenda, Usuario $promotor): Collection
    {
        return OrdemServico::withoutGlobalScopes()
            ->where('ponto_venda_id', $pontoVenda->id)
            ->where('status', StatusOrdemServico::PENDENTE)
            ->where(fn ($q) => $q->where('usuario_id', $promotor->id)->orWhereNull('usuario_id'))
            ->with('formularios')
            ->get();
    }

    private static function ordenar(Collection $ordens, string $dia, string $fuso): Collection
    {
        return $ordens->sort(function (OrdemServico $a, OrdemServico $b) use ($dia, $fuso): int {
            $grupo = self::grupo($a, $dia, $fuso) <=> self::grupo($b, $dia, $fuso);
            if ($grupo !== 0) {
                return $grupo;
            }

            $formulario = (int) self::temFormularioPendente($b) <=> (int) self::temFormularioPendente($a);
            if ($formulario !== 0) {
                return $formulario;
            }

            // Vencidas: a mais recente primeiro. Vigentes e futuras: a que vence antes.
            return self::grupo($a, $dia, $fuso) === self::VENCIDA
                ? $b->prazo_fim <=> $a->prazo_fim
                : $a->prazo_fim <=> $b->prazo_fim;
        })->values();
    }

    private static function grupo(OrdemServico $os, string $dia, string $fuso): int
    {
        $inicio = $os->prazo_inicio?->copy()->setTimezone($fuso)->toDateString();
        $fim = $os->prazo_fim?->copy()->setTimezone($fuso)->toDateString();

        return match (true) {
            $fim !== null && $fim < $dia => self::VENCIDA,
            $inicio !== null && $inicio > $dia => self::FUTURA,
            default => self::VIGENTE,
        };
    }

    private static function temFormularioPendente(OrdemServico $os): bool
    {
        return $os->formularios->contains(fn ($f) => $f->pivot->respondido_em === null);
    }
}

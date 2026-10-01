<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * Hora em que o promotor FEZ a ação no campo (check-in, checkout, registro), mandada pelo app —
 * docs/51-ENVIO-DA-FILA-EM-TEMPO-REAL.md §3.5/Fase 2. Antes o servidor gravava a hora em que
 * RECEBEU, e toda visita enviada com atraso (offline, fila parada) ficava com duração, atraso e
 * alinhamento com o GPS errados.
 *
 * A hora vem do relógio do celular, então tem rede de segurança: sem valor (APK antigo), no futuro
 * (relógio adiantado) ou velha demais pra ser confiável → a hora da chegada, como era antes.
 */
final class HorarioDoCampo
{
    /** Mais antigo que isso não é aceito como hora do campo (docs/51 §6 decisão 1). */
    public const LIMITE_RETROATIVO_HORAS = 48;

    /**
     * @param  Carbon|null  $naoAntesDe  ex.: o checkout não pode ser antes do check-in.
     */
    public static function resolver(?string $valor, ?Carbon $naoAntesDe = null): Carbon
    {
        $agora = now()->utc();
        $horario = Instante::normalizar($valor);

        if ($horario === null || $horario->gt($agora) || $horario->lt($agora->copy()->subHours(self::LIMITE_RETROATIVO_HORAS))) {
            $horario = $agora;
        }

        if ($naoAntesDe !== null && $horario->lt($naoAntesDe)) {
            $horario = $naoAntesDe->copy()->utc();
        }

        return $horario;
    }
}

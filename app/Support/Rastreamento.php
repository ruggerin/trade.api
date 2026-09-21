<?php

namespace App\Support;

use App\Models\Empresa;
use App\Models\Parametro;

/**
 * Rastreamento em tempo real (docs/11-RASTREAMENTO-TEMPO-REAL.md) — configuração por empresa via
 * `Parametro` `RASTREAMENTO_INTERVALO_SEGUNDOS`: ausente/inativo/0 = desligado (opt-in da
 * empresa); >0 = intervalo mínimo, em segundos, entre um envio de posição e outro.
 */
class Rastreamento
{
    /** Janela em que uma posição ainda conta como "ativo agora" (decisão 3 da doc). */
    public const JANELA_ATIVO_MINUTOS = 5;

    public static function intervaloSegundos(Empresa $empresa): int
    {
        $parametro = Parametro::query()
            ->where('empresa_id', $empresa->id)
            ->where('chave', 'RASTREAMENTO_INTERVALO_SEGUNDOS')
            ->where('ativo', true)
            ->first();

        if (! $parametro || ! is_numeric($parametro->valor)) {
            return 0;
        }

        return max(0, (int) $parametro->valor);
    }

    public static function habilitado(Empresa $empresa): bool
    {
        return self::intervaloSegundos($empresa) > 0;
    }
}

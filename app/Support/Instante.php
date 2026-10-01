<?php

namespace App\Support;

use DateTimeInterface;
use Illuminate\Support\Carbon;

/**
 * Instante é sempre UTC — docs/50-SUPORTE-MULTIPLOS-FUSOS-HORARIOS.md §2 princípio 1.
 *
 * Carbon com fuso ≠ UTC vai pro banco com a hora "de parede" daquele fuso, sem conversão: um
 * `2026-09-29T12:00:00-04:00` que chega do cliente seria gravado como 12:00, não 16:00. Todo
 * instante que vem de fora (request, arquivo importado) passa por aqui antes de gravar ou comparar.
 */
final class Instante
{
    /** Texto sem offset é tratado como UTC (o formato que a própria API devolve). */
    public static function normalizar(string|DateTimeInterface|null $valor): ?Carbon
    {
        if ($valor === null || $valor === '') {
            return null;
        }

        return ($valor instanceof DateTimeInterface ? Carbon::instance($valor) : Carbon::parse($valor, 'UTC'))->utc();
    }
}

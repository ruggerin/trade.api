<?php

namespace App\Relatorios;

use Carbon\Carbon;

/** O que uma métrica pode precisar além dos itens: o "agora" da execução e o fuso da empresa. */
final class Contexto
{
    public function __construct(
        public readonly Carbon $agora,
        public readonly string $tz,
    ) {}
}

<?php

namespace App\Enums;

/**
 * "Atrasada" não é um estado gravado de propósito — é calculado na hora de exibir
 * (status PENDENTE + vencimento no passado), pra não depender de um job agendado mantendo
 * isso em dia. Ver docs/02-API-BACKEND.md.
 */
enum StatusFatura: string
{
    case PENDENTE = 'PENDENTE';
    case PAGA = 'PAGA';
    case CANCELADA = 'CANCELADA';
}

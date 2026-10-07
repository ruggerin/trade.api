<?php

namespace App\Enums;

/**
 * Quem "causou" uma visita planejada não acontecer — docs/59. É a pergunta que expõe a
 * responsabilidade quando o promotor não vai à loja.
 */
enum ResponsavelNaoExecucao: string
{
    case PROMOTOR = 'PROMOTOR';
    case LOJA = 'LOJA';
    case EMPRESA = 'EMPRESA';
    case OUTRO = 'OUTRO';
}

<?php

namespace App\Enums;

/**
 * Tipo de intervenção administrativa numa Visita — ver App\Models\VisitaIntervencao e
 * docs/15-INTERVENCAO-ADMINISTRATIVA-VISITA.md.
 */
enum AcaoIntervencaoVisita: string
{
    case CANCELAMENTO = 'CANCELAMENTO';
    case CHECKOUT_FORCADO = 'CHECKOUT_FORCADO';
    case CORRECAO_HORARIO = 'CORRECAO_HORARIO';
}

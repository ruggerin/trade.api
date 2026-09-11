<?php

namespace App\Enums;

/**
 * Prioridade de uma OrdemServico ou AgendaVisita — só exibição/ordenação, não muda nenhuma
 * regra de negócio hoje. Ver docs/10-AGENDA-VISITA.md.
 */
enum PrioridadeVisita: string
{
    case BAIXA = 'BAIXA';
    case MEDIA = 'MEDIA';
    case ALTA = 'ALTA';
}

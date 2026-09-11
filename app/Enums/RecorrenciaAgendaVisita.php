<?php

namespace App\Enums;

/**
 * Tipo de recorrência de uma AgendaVisita — SEMANAL repete toda semana no mesmo dia
 * (`dia_semana`); DATA_UNICA dispara uma vez numa data específica (`data`) e se auto-desativa
 * depois de gerar. Ver docs/10-AGENDA-VISITA.md.
 */
enum RecorrenciaAgendaVisita: string
{
    case SEMANAL = 'SEMANAL';
    case DATA_UNICA = 'DATA_UNICA';
}

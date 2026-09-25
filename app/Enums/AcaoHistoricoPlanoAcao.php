<?php

namespace App\Enums;

/**
 * Tipo de linha do histórico append-only de um PlanoAcao — ver App\Models\PlanoAcaoHistorico e
 * docs/37-PLANOS-DE-ACAO.md §4.9.
 */
enum AcaoHistoricoPlanoAcao: string
{
    case PLANO_CRIADO = 'PLANO_CRIADO';
    case PLANO_CONCLUIDO = 'PLANO_CONCLUIDO';
    case PLANO_CANCELADO = 'PLANO_CANCELADO';
    case ETAPA_ADICIONADA = 'ETAPA_ADICIONADA';
    case ETAPA_STATUS_ALTERADO = 'ETAPA_STATUS_ALTERADO';
}

<?php

namespace App\Enums;

/**
 * Os 3 níveis de autonomia do promotor sobre uma ação de self-service (vincular produto ao
 * sortimento do PDV, ou cadastrar produto novo no catálogo) — configurável por empresa via
 * `Parametro`. Ver docs/14-SORTIMENTO-PONTO-VENDA.md §9.
 */
enum AutonomiaPromotor: string
{
    // Promotor não vê a opção — some da tela do mobile.
    case DESABILITADO = 'DESABILITADO';
    // Promotor faz direto, sem aprovação nenhuma.
    case AUTONOMO = 'AUTONOMO';
    // Promotor faz normalmente (nunca fica bloqueado), mas o resultado nasce pendente até um
    // gestor decidir.
    case REQUER_APROVACAO = 'REQUER_APROVACAO';
}

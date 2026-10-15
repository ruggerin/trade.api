<?php

namespace App\Enums;

/**
 * Situação COMERCIAL do pedido do ERP (docs/61 §5.1) — não confundir com o status de ENTREGA
 * (A_CAMINHO/ATRASADO/ENTREGUE, App\Support\NotificacoesPedido::status).
 */
enum StatusFaturamento: string
{
    case NAO_FATURADO = 'NAO_FATURADO';
    case FATURADO = 'FATURADO';
    case CANCELADO = 'CANCELADO';
    // O Winthor emite a devolução como faturamento de valor negativo.
    case DEVOLVIDO = 'DEVOLVIDO';
}

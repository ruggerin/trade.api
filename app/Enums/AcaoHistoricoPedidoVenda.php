<?php

namespace App\Enums;

/**
 * Tipo de linha do histórico append-only de um PedidoVenda — ver App\Models\PedidoVendaHistorico
 * e docs/38-PEDIDO-VENDEDOR.md §6.
 */
enum AcaoHistoricoPedidoVenda: string
{
    case CRIADO = 'CRIADO';
    case ITEM_ALTERADO = 'ITEM_ALTERADO';
    case AUTORIZACAO_SOLICITADA = 'AUTORIZACAO_SOLICITADA';
    case APROVADO = 'APROVADO';
    case REJEITADO = 'REJEITADO';
    case CONCLUIDO = 'CONCLUIDO';
    case CANCELADO = 'CANCELADO';
}

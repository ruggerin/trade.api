<?php

namespace App\Enums;

/**
 * Status de um PedidoVenda — máquina de estados de docs/38-PEDIDO-VENDEDOR.md §7.
 * CONCLUIDO/CANCELADO são terminais: corrigir depois disso é um pedido novo, não reabertura.
 */
enum StatusPedidoVenda: string
{
    case RASCUNHO = 'RASCUNHO';
    case PENDENTE_AUTORIZACAO = 'PENDENTE_AUTORIZACAO';
    case APROVADO = 'APROVADO';
    case CONCLUIDO = 'CONCLUIDO';
    case CANCELADO = 'CANCELADO';

    public function terminal(): bool
    {
        return in_array($this, [self::CONCLUIDO, self::CANCELADO], true);
    }

    /** Itens/cabeçalho só mudam em RASCUNHO ou APROVADO (esse volta pra RASCUNHO, §7). */
    public function editavel(): bool
    {
        return in_array($this, [self::RASCUNHO, self::APROVADO], true);
    }
}

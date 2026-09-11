<?php

namespace App\Enums;

/**
 * Status de aprovação de um item criado por promotor em modo `REQUER_APROVACAO`
 * (App\Enums\AutonomiaPromotor) — coluna sempre nullable, NULL = não se aplica (cadastro normal
 * por admin/gestor, ou promotor em modo autônomo). Ver docs/14-SORTIMENTO-PONTO-VENDA.md §9.
 *
 * `sortimentos_ponto_venda.status_aprovacao` só usa PENDENTE — rejeitar um item de sortimento
 * apaga a linha (nada mais referencia), então REJEITADO nunca é persistido ali. Já
 * `produtos_auditoria.status_aprovacao` usa os dois, porque o produto pode já ter um
 * VisitaRegistro apontando pra ele (não dá pra apagar).
 */
enum StatusAprovacao: string
{
    case PENDENTE = 'PENDENTE';
    case REJEITADO = 'REJEITADO';
}

<?php

namespace App\Enums;

/**
 * Status de uma OrdemServico. Sem um valor `EXPIRADA` persistido de propósito — "expirada" é
 * calculado na exibição (PENDENTE + prazo_fim no passado), mesmo padrão já usado por
 * StatusFatura::class ("Atrasada" não é um estado gravado, ver Fatura), pra não precisar de job
 * agendado só pra manter esse campo em dia.
 *
 * Os três últimos valores só existem quando a empresa tem `AGENDA_REQUER_APROVACAO` ativo (ver
 * App\Support\AutonomiaAgenda) — em modo autônomo o promotor aplica a ação direto
 * (PENDENTE/CANCELADA), nunca passa por eles. Ver docs/13-AGENDA-MOBILE-E-AUTONOMIA.md.
 */
enum StatusOrdemServico: string
{
    case PENDENTE = 'PENDENTE';
    case EM_ANDAMENTO = 'EM_ANDAMENTO';
    case CONCLUIDA = 'CONCLUIDA';
    case CANCELADA = 'CANCELADA';
    // Promotor criou um compromisso novo (self-service) e a empresa exige aprovação — a OS já
    // existe com os dados propostos, só o status muda quando o gestor decide.
    case AGUARDANDO_APROVACAO = 'AGUARDANDO_APROVACAO';
    // Promotor propôs um novo prazo pra uma OS já confirmada — prazo_inicio/prazo_fim
    // continuam com o valor oficial atual; o proposto fica em prazo_inicio_proposto/
    // prazo_fim_proposto até o gestor aprovar (copia proposto → oficial) ou rejeitar (só limpa
    // as colunas propostas).
    case REAGENDAMENTO_SOLICITADO = 'REAGENDAMENTO_SOLICITADO';
    // Promotor pediu cancelamento de uma OS já confirmada — aprovar vira CANCELADA, rejeitar
    // volta pra PENDENTE.
    case CANCELAMENTO_SOLICITADO = 'CANCELAMENTO_SOLICITADO';
}

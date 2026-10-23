<?php

namespace App\Enums;

/**
 * Como o checkout de uma Visita foi feito: PROMOTOR (fluxo normal pelo app, com GPS) ou ADMIN
 * (forçado por um gestor via intervenção administrativa, sem GPS — ver
 * docs/15-INTERVENCAO-ADMINISTRATIVA-VISITA.md). `null` enquanto a visita está ABERTA. Deixa o
 * relatório de custo/hora distinguir checkout real de forçado sem join na tabela de auditoria.
 *
 * docs/92: SAIDA_DETECTADA (o promotor confirmou finalizar no horário em que saiu da loja) e
 * AUTOMATICO (o servidor fechou sozinho, no horário da saída, depois de N minutos longe).
 */
enum CheckoutTipo: string
{
    case PROMOTOR = 'PROMOTOR';
    case ADMIN = 'ADMIN';
    case SAIDA_DETECTADA = 'SAIDA_DETECTADA';
    case AUTOMATICO = 'AUTOMATICO';
}

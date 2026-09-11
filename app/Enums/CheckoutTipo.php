<?php

namespace App\Enums;

/**
 * Como o checkout de uma Visita foi feito: PROMOTOR (fluxo normal pelo app, com GPS) ou ADMIN
 * (forçado por um gestor via intervenção administrativa, sem GPS — ver
 * docs/15-INTERVENCAO-ADMINISTRATIVA-VISITA.md). `null` enquanto a visita está ABERTA. Deixa o
 * relatório de custo/hora distinguir checkout real de forçado sem join na tabela de auditoria.
 */
enum CheckoutTipo: string
{
    case PROMOTOR = 'PROMOTOR';
    case ADMIN = 'ADMIN';
}

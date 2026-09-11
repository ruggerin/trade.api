<?php

namespace App\Enums;

/**
 * Compartilhado por marcas_auditoria e produtos_auditoria — distingue marca/produto próprio
 * (do cliente contratante da auditoria) de concorrente. Núcleo da inteligência competitiva.
 */
enum Propriedade: string
{
    case PROPRIA = 'PROPRIA';
    case CONCORRENTE = 'CONCORRENTE';
}

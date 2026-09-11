<?php

namespace App\Enums;

/**
 * Marca um registro avulso ("Registro geral", sem produto vinculado) como foto de "antes" ou
 * "depois" de uma ação do promotor na loja (ex.: reposição de gôndola) — nullable, só faz
 * sentido pra quem quiser usar esse par; um registro solto sem essa marcação continua válido.
 */
enum MomentoRegistro: string
{
    case ANTES = 'ANTES';
    case DEPOIS = 'DEPOIS';
}

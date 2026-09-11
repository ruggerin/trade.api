<?php

namespace App\Enums;

/**
 * Tipo de dado de um campo customizado de `TipoRegistro` — define como o app mobile renderiza
 * o input e como o valor em `VisitaRegistro.valores_campos` deve ser validado/interpretado. Ver
 * docs/01-MODELO-DE-DADOS.md.
 */
enum TipoCampoRegistro: string
{
    case NUMERO = 'NUMERO';
    case TEXTO = 'TEXTO';
    case MOEDA = 'MOEDA';
    case MULTIPLA_ESCOLHA = 'MULTIPLA_ESCOLHA';
}

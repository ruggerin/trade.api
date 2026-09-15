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
    // Valor sempre "0"/"1" em valores_campos, mesma convenção do campo `ruptura` já existente.
    case BOOLEANO = 'BOOLEANO';
    // Valor sempre "dd/mm/aaaa" em valores_campos — o mobile nunca manda um formato de data
    // diferente (sem calendário nativo, ver docs/20-FORMULARIO-DINAMICO-CAMPANHA.md §5.4).
    case DATA = 'DATA';
}

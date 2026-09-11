<?php

namespace App\Enums;

/**
 * Quem banca o valor investido numa ContratoMeta (verba de trade marketing) — ver
 * docs/09-CONTRATO-METAS.md §3/§4. COMPARTILHADO exige `percentual_industria` preenchido
 * (validado em Store/UpdateContratoMetaRequest); EMPRESA/INDUSTRIA não usam esse campo.
 */
enum FontePagamentoMeta: string
{
    case EMPRESA = 'EMPRESA';
    case INDUSTRIA = 'INDUSTRIA';
    case COMPARTILHADO = 'COMPARTILHADO';
}

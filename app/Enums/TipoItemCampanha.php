<?php

namespace App\Enums;

/**
 * Discriminador de campanha_itens — diz qual FK (produto/secao/departamento/marca) é a
 * relevante naquele item. Ver regra de negócio 2 em docs/02-API-BACKEND.md.
 */
enum TipoItemCampanha: string
{
    case PRODUTO = 'PRODUTO';
    case SECAO = 'SECAO';
    case DEPARTAMENTO = 'DEPARTAMENTO';
    case MARCA = 'MARCA';
}

<?php

namespace App\Enums;

/**
 * Categoria de um item de custo dentro de um CentroCusto — ver docs/08-CENTRO-DE-CUSTO.md.
 * INDIVIDUAL: custo mensal que existe por promotor (salário, impostos...). GERAL: custo mensal
 * compartilhado entre todos os promotores do mesmo centro de custo (sistema, telefonia...),
 * dividido pela quantidade de promotores vinculados na hora do cálculo (nunca persistido).
 */
enum CategoriaCentroCustoItem: string
{
    case INDIVIDUAL = 'INDIVIDUAL';
    case GERAL = 'GERAL';
}

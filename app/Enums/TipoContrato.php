<?php

namespace App\Enums;

/**
 * Tipos de contrato entre a empresa e o ponto de venda — ver docs/01-MODELO-DE-DADOS.md e
 * docs/07-ORDEM-DE-SERVICO.md §5 (OrdemServico.origem = CONTRATO gera aviso de vencimento a
 * partir de um Contrato, ver App\Console\Commands\GerarOrdensServicoPorContrato). Catálogo fixo
 * em código, igual a Permissao — cada tipo novo (ex.: locação de espaço, degustação) exige
 * código novo, não é configurável por empresa.
 */
enum TipoContrato: string
{
    case COMODATO = 'COMODATO';
    case PONTO_EXTRA = 'PONTO_EXTRA';
}

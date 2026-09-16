<?php

namespace App\Enums;

/**
 * Origem de uma OrdemServico — de onde ela nasceu, ver docs/07-ORDEM-DE-SERVICO.md e
 * docs/10-AGENDA-VISITA.md. `CONTRATO` — gerada por
 * App\Console\Commands\GerarOrdensServicoPorContrato quando um comodato/ponto extra se aproxima
 * do vencimento.
 */
enum OrigemOrdemServico: string
{
    case MANUAL = 'MANUAL';
    case CAMPANHA = 'CAMPANHA';
    case AGENDA = 'AGENDA';
    case CONTRATO = 'CONTRATO';
    // Gerada em massa por App\Console\Commands\GerarOrdensServicoPorDirecionamento — uma por PDV
    // elegível pelos filtros do Direcionamento, ver docs/25-DIRECIONAMENTO-ORDEM-SERVICO.md.
    case DIRECIONAMENTO = 'DIRECIONAMENTO';
}

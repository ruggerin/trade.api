<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Geração automática de OrdemServico por campanha recorrente — ver
// App\Console\Commands\GerarOrdensServicoPorCampanha e docs/07-ORDEM-DE-SERVICO.md. Diária
// basta: a janela de cada ciclo é medida em dias (frequencia_dias), não em horas.
Schedule::command('ordens-servico:gerar-por-campanha')->daily();

// Geração automática de OrdemServico por agenda de visita do promotor (regra semanal ou data
// única) — ver App\Console\Commands\GerarOrdensServicoPorAgenda e docs/10-AGENDA-VISITA.md.
// Cedo de manhã, antes do horário comercial, pra já aparecer pro promotor no início do dia.
Schedule::command('ordens-servico:gerar-por-agenda')->dailyAt('05:00');

// Geração automática de OrdemServico por Contrato (comodato/ponto extra) vencendo — ver
// App\Console\Commands\GerarOrdensServicoPorContrato e docs/07-ORDEM-DE-SERVICO.md §5. Diária
// basta, a janela de aviso é medida em dias (CONTRATO_AVISO_DIAS), não em horas.
Schedule::command('ordens-servico:gerar-por-contrato')->dailyAt('05:00');

// Reforço diário de Direcionamento — a primeira leva já é gerada síncrona ao salvar
// (DirecionamentoController::store/update), isso aqui só cobre PDV/promotor que virou elegível
// durante a vigência (ex.: promotor novo atribuído a uma loja da rede-alvo). Ver
// App\Console\Commands\GerarOrdensServicoPorDirecionamento e
// docs/25-DIRECIONAMENTO-ORDEM-SERVICO.md §4.
Schedule::command('ordens-servico:gerar-por-direcionamento')->daily();

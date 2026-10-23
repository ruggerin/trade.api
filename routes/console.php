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

// Batimento do agendador (docs/71): a cada minuto grava a hora no cache. O endpoint /api/saude lê
// isto pra saber se o cron `schedule:run` está vivo — sem batimento, as rotinas diárias não rodam.
Schedule::call(fn () => \Illuminate\Support\Facades\Cache::put(\App\Support\Saude\VerificacaoSaude::CHAVE_BATIMENTO, now()->getTimestamp(), 3600))
    ->everyMinute()
    ->name('saude-batimento')
    ->withoutOverlapping(2);

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

// Rota do dia (docs/48-ROTA-DO-DIA.md): apaga posições mais velhas que RASTREAMENTO_HISTORICO_DIAS
// de cada empresa (padrão 90 dias) — histórico de posição é dado pessoal, não fica pra sempre.
Schedule::command('rastreamento:limpar-historico')->dailyAt('03:30');

// Afastamento durante a visita (docs/49-AFASTAMENTO-DURANTE-VISITA.md §6): grava o resumo nas
// visitas finalizadas há 30+ min (espera posição atrasada do app) — filtro da lista e feed.
Schedule::command('visitas:calcular-afastamento')->everyTenMinutes();

// Simulador de campo — SÓ servidor de teste/demonstração (config/simulador.php). Desligado por
// padrão: sem SIMULADOR_ATIVIDADE_EMPRESAS no .env nem entra na fila. O próprio comando respeita
// a jornada da empresa (JORNADA_INICIO/JORNADA_FIM).
Schedule::command('dev:simular-atividade')->everyFiveMinutes()->withoutOverlapping()
    ->when(fn (): bool => config('simulador.empresas') !== []);

// Sessões do app (docs/67 §4.4): tokens revogados/vencidos há 30+ dias e desafios de atestação vencidos.
Schedule::command('sessoes:limpar')->dailyAt('03:45');

// "Ainda está lá?" (docs/83): prazo máximo vencido vira EXPIRADO.
Schedule::command('acompanhamentos:expirar')->dailyAt('03:50');

// Régua de XP (docs/88): de hora em hora, cada empresa no próprio fuso — na primeira hora do dia
// fecha ontem (OS não feitas viram perda) e, na segunda, a liga da semana passada.
Schedule::command('xp:fechar-dia')->hourlyAt(30)->withoutOverlapping(30);

// Sugestão de classe de loja pela mediana do tempo de visita (docs/88 §4) — o gestor aceita.
Schedule::command('xp:sugerir-classes')->weeklyOn(1, '04:30');

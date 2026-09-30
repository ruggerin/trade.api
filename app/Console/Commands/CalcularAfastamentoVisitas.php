<?php

namespace App\Console\Commands;

use App\Enums\StatusVisita;
use App\Models\Visita;
use App\Support\AfastamentoVisita;
use Illuminate\Console\Command;

/**
 * Grava o resumo do afastamento das visitas finalizadas (docs/49-AFASTAMENTO-DURANTE-VISITA.md §6,
 * fase 2). Espera ESPERA_MINUTOS depois do checkout porque o app pode mandar posição atrasada;
 * só olha os últimos JANELA_DIAS pra não varrer visita antiga que nunca teve histórico.
 */
class CalcularAfastamentoVisitas extends Command
{
    protected $signature = 'visitas:calcular-afastamento';

    protected $description = 'Calcula e grava o afastamento durante a visita das visitas finalizadas há pelo menos 30 min';

    private const ESPERA_MINUTOS = 30;

    private const JANELA_DIAS = 2;

    public function handle(): int
    {
        $total = 0;

        Visita::query()
            ->withoutGlobalScopes()
            ->where('status', '!=', StatusVisita::CANCELADA)
            ->whereNotNull('fim_data')
            ->whereNull('afastamento_calculado_em')
            ->where('fim_data', '<=', now()->subMinutes(self::ESPERA_MINUTOS))
            ->where('fim_data', '>=', now()->subDays(self::JANELA_DIAS))
            ->chunkById(200, function ($visitas) use (&$total) {
                foreach ($visitas as $visita) {
                    AfastamentoVisita::gravar($visita);
                    $total++;
                }
            });

        $this->info("{$total} visita(s) calculada(s).");

        return self::SUCCESS;
    }
}

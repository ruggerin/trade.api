<?php

namespace App\Console\Commands;

use App\Support\Webhooks;
use Illuminate\Console\Command;

/**
 * Entrega a fila de webhooks das integrações (docs/96 Fase 4) — a cada minuto pelo cron, já que a
 * API não tem worker de fila. Ver App\Support\Webhooks.
 */
class EntregarWebhooksIntegracao extends Command
{
    protected $signature = 'integracoes:entregar-webhooks {--limite=200}';

    protected $description = 'Entrega os avisos pendentes dos webhooks das integrações (com novas tentativas)';

    public function handle(): int
    {
        $resultado = Webhooks::entregarPendentes((int) $this->option('limite'));

        $this->info("{$resultado['entregues']} aviso(s) entregue(s), {$resultado['falhas']} falha(s).");

        return self::SUCCESS;
    }
}

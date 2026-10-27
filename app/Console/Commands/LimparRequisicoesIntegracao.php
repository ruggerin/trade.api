<?php

namespace App\Console\Commands;

use App\Http\Middleware\EnsureIdempotencia;
use App\Support\Webhooks;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Limpeza diária da API de integração (docs/96): auditoria com mais de 90 dias (§11 #7), chaves de
 * idempotência vencidas (24 h) e avisos de webhook já resolvidos (entregues ou abandonados) com
 * mais de 30 dias.
 */
class LimparRequisicoesIntegracao extends Command
{
    public const DIAS_RETENCAO = 90;

    protected $signature = 'integracoes:limpar-requisicoes';

    protected $description = 'Apaga a auditoria (90 dias), as chaves de idempotência (24 h) e os avisos de webhook resolvidos (30 dias) das integrações';

    public function handle(): int
    {
        $requisicoes = DB::table('integracao_requisicoes')
            ->where('created_at', '<', now()->subDays(self::DIAS_RETENCAO))
            ->delete();

        $chaves = DB::table('integracao_idempotencia')
            ->where('created_at', '<', now()->subHours(EnsureIdempotencia::HORAS_VALIDADE))
            ->delete();

        $avisos = DB::table('integracao_webhook_entregas')
            ->where('created_at', '<', now()->subDays(Webhooks::DIAS_RETENCAO))
            ->where(fn ($q) => $q->whereNotNull('entregue_em')->orWhereNotNull('desistiu_em'))
            ->delete();

        $this->info("{$requisicoes} requisição(ões), {$chaves} chave(s) de idempotência e {$avisos} aviso(s) de webhook apagados.");

        return self::SUCCESS;
    }
}

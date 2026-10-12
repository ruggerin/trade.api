<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Apaga tokens que não servem mais (docs/67 §4.4): revogados há mais de 30 dias (o motivo já foi
 * mostrado ao app há muito tempo) e vencidos há mais de 30 dias. Desafios de atestação vencidos
 * (Fase 3) também saem. Agendado diariamente em routes/console.php.
 */
class LimparSessoes extends Command
{
    protected $signature = 'sessoes:limpar';

    protected $description = 'Apaga tokens revogados/vencidos há mais de 30 dias e desafios de atestação vencidos';

    public function handle(): int
    {
        $corte = now()->subDays(30);

        $tokens = DB::table('personal_access_tokens')
            ->where(fn ($q) => $q->where('revogado_em', '<', $corte)->orWhere('expires_at', '<', $corte))
            ->delete();

        $desafios = DB::table('desafios_atestacao')->where('expira_em', '<', now())->delete();

        $this->info("{$tokens} token(s) e {$desafios} desafio(s) apagados.");

        return self::SUCCESS;
    }
}

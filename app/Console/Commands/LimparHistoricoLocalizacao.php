<?php

namespace App\Console\Commands;

use App\Models\Empresa;
use App\Support\Rastreamento;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Apaga posições do histórico da Rota do dia mais velhas que RASTREAMENTO_HISTORICO_DIAS de cada
 * empresa (padrão 90) — docs/48-ROTA-DO-DIA.md §4.1. Posição de funcionário é dado pessoal: não
 * pode ficar guardada pra sempre. Leva junto a linha já calculada (`rotas_dia`) dos dias apagados.
 * Agendado diariamente em routes/console.php.
 */
class LimparHistoricoLocalizacao extends Command
{
    protected $signature = 'rastreamento:limpar-historico';

    protected $description = 'Apaga posições da Rota do dia mais antigas que RASTREAMENTO_HISTORICO_DIAS de cada empresa';

    public function handle(): int
    {
        $totalPontos = 0;

        foreach (Empresa::query()->withoutGlobalScopes()->get() as $empresa) {
            $corte = now()->subDays(Rastreamento::historicoDias($empresa))->startOfDay();

            $totalPontos += DB::table('localizacoes_historico')
                ->where('empresa_id', $empresa->id)
                ->where('capturado_em', '<', $corte)
                ->delete();

            DB::table('rotas_dia')
                ->whereIn('usuario_id', fn ($q) => $q->select('id')->from('usuarios')->where('empresa_id', $empresa->id))
                ->where('data', '<', $corte->toDateString())
                ->delete();
        }

        $this->info("{$totalPontos} posição(ões) antiga(s) apagada(s).");

        return self::SUCCESS;
    }
}

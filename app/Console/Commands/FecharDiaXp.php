<?php

namespace App\Console\Commands;

use App\Models\Empresa;
use App\Support\Xp\DiaXp;
use App\Support\Xp\LigaXp;
use App\Support\Xp\ReguaXp;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Fechamento da régua de XP (docs/88 §6, §12.4). Roda de hora em hora e, em cada empresa com o
 * recurso ligado, age no fuso dela: na primeira hora do dia fecha ONTEM (OS não feitas viram
 * perda "visita não realizada"); na primeira hora de segunda fecha a liga da semana passada.
 * Idempotente — rodar de novo não duplica nada.
 */
class FecharDiaXp extends Command
{
    protected $signature = 'xp:fechar-dia {--dia= : Dia a fechar (YYYY-MM-DD), em todas as empresas com XP} {--forcar : Fecha mesmo fora da primeira hora}';

    protected $description = 'Fecha o dia da régua de XP (visitas não realizadas) e, na segunda, a liga semanal';

    public function handle(): int
    {
        $empresas = Empresa::query()->where('controle_xp_habilitado', true)->where('ativo', true)->get();
        foreach ($empresas as $empresa) {
            $fuso = DiaXp::fusoDa($empresa);
            $agora = now($fuso);
            if (! $this->option('dia') && ! $this->option('forcar') && $agora->hour !== 0) {
                continue;
            }
            $dia = $this->option('dia') ?? $agora->copy()->subDay()->toDateString();
            $oss = ReguaXp::fecharDia($empresa, $dia);
            $this->line("{$empresa->nome_fantasia}: dia {$dia} fechado ({$oss} OS).");

            if (Carbon::parse($dia)->isSunday()) {
                $semana = DiaXp::inicioDaSemana($dia);
                $linhas = LigaXp::fecharSemana($empresa, $semana);
                $this->line("{$empresa->nome_fantasia}: liga da semana {$semana} fechada ({$linhas} posições).");
            }
        }

        return self::SUCCESS;
    }
}

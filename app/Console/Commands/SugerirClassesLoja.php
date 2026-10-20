<?php

namespace App\Console\Commands;

use App\Enums\StatusVisita;
use App\Models\ClassePontoVenda;
use App\Models\Empresa;
use App\Models\PontoVenda;
use App\Models\Visita;
use Illuminate\Console\Command;

/**
 * Sugestão de classe de loja (docs/88 §4): pela mediana do tempo real das visitas dos últimos 90
 * dias, a classe com o tempo-padrão mais perto. Só sugere — o gestor aceita na tela de Classes
 * (o tempo nunca muda a classe sozinho, senão o promotor aprenderia a enrolar). Loja com menos de
 * 3 visitas no período fica sem sugestão.
 */
class SugerirClassesLoja extends Command
{
    protected $signature = 'xp:sugerir-classes';

    protected $description = 'Atualiza a classe sugerida de cada loja pela mediana do tempo de visita';

    private const MINIMO_VISITAS = 3;

    public function handle(): int
    {
        foreach (Empresa::query()->where('controle_xp_habilitado', true)->get() as $empresa) {
            $classes = ClassePontoVenda::withoutGlobalScopes()->where('empresa_id', $empresa->id)->where('ativo', true)->get();
            if ($classes->isEmpty()) {
                continue;
            }
            $sugeridas = 0;
            $lojas = PontoVenda::withoutGlobalScopes()->where('empresa_id', $empresa->id)->where('ativo', true)->get();
            foreach ($lojas as $loja) {
                $mediana = self::mediana($loja);
                $classe = $mediana === null ? null : $classes->sortBy(fn (ClassePontoVenda $c) => abs($c->duracao_padrao_minutos - $mediana))->first();
                if ($loja->classe_sugerida_id !== $classe?->id) {
                    $loja->forceFill(['classe_sugerida_id' => $classe?->id])->saveQuietly();
                    $sugeridas++;
                }
            }
            $this->line("{$empresa->nome_fantasia}: {$sugeridas} sugestão(ões) atualizada(s).");
        }

        return self::SUCCESS;
    }

    private static function mediana(PontoVenda $loja): ?float
    {
        $duracoes = Visita::withoutGlobalScopes()
            ->where('ponto_venda_id', $loja->id)
            ->where('status', StatusVisita::FINALIZADA)
            ->where('inicio_data', '>=', now()->subDays(90))
            ->whereNotNull('fim_data')
            ->get(['inicio_data', 'fim_data'])
            ->map(fn (Visita $v) => $v->inicio_data->diffInSeconds($v->fim_data) / 60)
            ->filter(fn ($m) => $m > 0)
            ->sort()
            ->values();
        if ($duracoes->count() < self::MINIMO_VISITAS) {
            return null;
        }

        return (float) $duracoes->median();
    }
}

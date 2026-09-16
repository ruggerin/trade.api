<?php

namespace App\Support;

use App\Enums\StatusOrdemServico;
use App\Models\Direcionamento;
use App\Models\OrdemServico;
use Illuminate\Support\Facades\DB;

/**
 * "N expedidos, M preenchidos" — docs/25-DIRECIONAMENTO-ORDEM-SERVICO.md §2 decisão 4. Calculado
 * ao vivo a partir de `ordem_servico_formularios` (nunca um contador solto, sempre uma consulta
 * agregada sobre a linha por obrigação) — total e quebrado por formulário.
 */
class ProgressoDirecionamento
{
    public static function calcular(Direcionamento $direcionamento): array
    {
        $ordens = OrdemServico::withoutGlobalScopes()
            ->where('direcionamento_id', $direcionamento->id)
            ->get(['id', 'status']);

        $porFormulario = DB::table('ordem_servico_formularios')
            ->join('ordens_servico', 'ordens_servico.id', '=', 'ordem_servico_formularios.ordem_servico_id')
            ->join('tipos_registro', 'tipos_registro.id', '=', 'ordem_servico_formularios.tipo_registro_id')
            ->where('ordens_servico.direcionamento_id', $direcionamento->id)
            ->selectRaw(
                'tipos_registro.uuid as tipo_registro_uuid, tipos_registro.descricao as descricao, '
                .'count(*) as expedidos, count(ordem_servico_formularios.respondido_em) as preenchidos',
            )
            ->groupBy('tipos_registro.uuid', 'tipos_registro.descricao')
            ->get();

        return [
            'ordens_geradas' => $ordens->count(),
            'ordens_concluidas' => $ordens->where('status', StatusOrdemServico::CONCLUIDA)->count(),
            'ordens_pendentes' => $ordens->whereIn('status', [StatusOrdemServico::PENDENTE, StatusOrdemServico::EM_ANDAMENTO])->count(),
            'por_formulario' => $porFormulario->map(fn ($linha) => [
                'tipo_registro_id' => $linha->tipo_registro_uuid,
                'descricao' => $linha->descricao,
                'expedidos' => (int) $linha->expedidos,
                'preenchidos' => (int) $linha->preenchidos,
            ])->values(),
        ];
    }
}

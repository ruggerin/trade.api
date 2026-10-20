<?php

namespace App\Support\Xp;

use App\Models\ClassePontoVenda;
use App\Models\Empresa;
use App\Models\XpLancamento;
use App\Models\XpResumoDiario;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Conquistas (docs/88 §12.5), calculadas na hora a partir dos lançamentos — nada é gravado.
 */
final class ConquistasXp
{
    /** @return list<array{chave: string, titulo: string, descricao: string, conquistada: bool, progresso: int, meta: int}> */
    public static function do(Empresa $empresa, int $usuarioId): array
    {
        // Visitas que pontuaram e o XP de cada uma contra o possível.
        $visitas = XpLancamento::withoutGlobalScopes()
            ->where('usuario_id', $usuarioId)
            ->where('status', XpLancamento::OFICIAL)
            ->whereNotNull('visita_id')
            ->whereNotIn('categoria', [ComposicaoXp::INICIATIVA, ComposicaoXp::BONUS_ACAO])
            ->select('visita_id', 'ordem_servico_id',
                DB::raw("SUM(CASE WHEN natureza <> 'PERDA' THEN pontos ELSE 0 END) AS ganho"),
                DB::raw("SUM(CASE WHEN natureza = 'PERDA' THEN pontos ELSE 0 END) AS perda"))
            ->groupBy('visita_id', 'ordem_servico_id')
            ->get();
        $pontuadas = $visitas->filter(fn ($v) => (int) $v->ganho > 0);
        $perfeitas = $pontuadas->filter(fn ($v) => (int) $v->perda === 0)->count();

        $maiorClasse = ClassePontoVenda::withoutGlobalScopes()->where('empresa_id', $empresa->id)->where('ativo', true)->orderByDesc('xp_base')->first();
        $chefoes = $maiorClasse
            ? DB::table('ordens_servico')->whereIn('id', $pontuadas->pluck('ordem_servico_id')->filter())->where('classe_id', $maiorClasse->id)->count()
            : 0;

        $iniciativas = XpLancamento::withoutGlobalScopes()
            ->where('usuario_id', $usuarioId)->where('status', XpLancamento::OFICIAL)
            ->where('categoria', ComposicaoXp::INICIATIVA)->where('pontos', '>', 0)->count();

        $mesPerfeito = self::mesPerfeito($empresa, $usuarioId);

        $itens = [
            ['chave' => 'cem_visitas', 'titulo' => '100 visitas', 'descricao' => '100 visitas da agenda pontuadas.', 'progresso' => $pontuadas->count(), 'meta' => 100],
            ['chave' => 'loja_perfeita', 'titulo' => 'Loja perfeita', 'descricao' => 'Uma visita sem nenhum XP pra trás.', 'progresso' => min(1, $perfeitas), 'meta' => 1],
            ['chave' => 'dez_chefoes', 'titulo' => '10 '.($maiorClasse?->nome ?? 'lojas grandes'), 'descricao' => 'Visitas na classe de loja que mais vale.', 'progresso' => $chefoes, 'meta' => 10],
            ['chave' => 'primeira_iniciativa', 'titulo' => 'Primeira iniciativa', 'descricao' => 'Um registro de iniciativa que contou XP.', 'progresso' => min(1, $iniciativas), 'meta' => 1],
            ['chave' => 'mes_perfeito', 'titulo' => 'Mês perfeito', 'descricao' => 'Um mês inteiro com a agenda de todos os dias cumprida.', 'progresso' => $mesPerfeito ? 1 : 0, 'meta' => 1],
        ];

        return array_map(fn ($i) => $i + ['conquistada' => $i['progresso'] >= $i['meta']], $itens);
    }

    /** Algum mês já fechado em que todo dia com OS teve a agenda cumprida. */
    private static function mesPerfeito(Empresa $empresa, int $usuarioId): bool
    {
        $inicioMesAtual = now(DiaXp::fusoDa($empresa))->startOfMonth()->toDateString();
        $porMes = XpResumoDiario::withoutGlobalScopes()
            ->where('usuario_id', $usuarioId)
            ->where('dia', '<', $inicioMesAtual)
            ->where('visitas_previstas', '>', 0)
            ->get()
            ->groupBy(fn (XpResumoDiario $r) => Carbon::parse($r->dia)->format('Y-m'));

        return $porMes->contains(fn ($dias) => $dias->count() >= 15
            && $dias->every(fn (XpResumoDiario $r) => $r->visitas_feitas + $r->visitas_protegidas >= $r->visitas_previstas));
    }
}

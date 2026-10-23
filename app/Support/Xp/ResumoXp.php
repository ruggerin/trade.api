<?php

namespace App\Support\Xp;

use App\Enums\ResponsavelNaoExecucao;
use App\Enums\StatusVisita;
use App\Models\Empresa;
use App\Models\OrdemServico;
use App\Models\Visita;
use App\Models\XpLancamento;
use App\Models\XpResumoDiario;
use App\Support\Fuso;
use Illuminate\Support\Collection;

/**
 * Resumo da régua por promotor e dia (docs/88 §14): possível (das OS do dia), realizado, iniciativa,
 * pendente e perdas por categoria (dos lançamentos). Equipe e empresa somam daqui.
 */
final class ResumoXp
{
    /** OS que contam num dia (com XP congelado), no fuso da empresa. @return Collection<int, OrdemServico> */
    public static function osDoDia(Empresa $empresa, string $dia): Collection
    {
        $fuso = Fuso::daEmpresa($empresa);
        [$inicio, $fim] = Fuso::intervaloDoDia($dia, $fuso);

        return OrdemServico::withoutGlobalScopes()
            ->where('empresa_id', $empresa->id)
            ->whereNotNull('xp_previsto')
            ->where(fn ($q) => $q->whereBetween('prazo_fim', [$inicio, $fim])
                ->orWhere(fn ($q) => $q->whereNull('prazo_fim')->whereBetween('prazo_inicio', [$inicio, $fim])))
            ->get()
            ->filter(fn (OrdemServico $os) => DiaXp::daOs($os, $fuso) === $dia)
            ->values();
    }

    /** @return list<int> promotores com OS ou lançamento no dia */
    public static function usuariosDoDia(Empresa $empresa, string $dia): array
    {
        // toBase(): ids, não models — a coleção Eloquent vazia (dia sem OS) não aceita o merge com ids.
        $daOs = self::osDoDia($empresa, $dia)->toBase()->map(fn (OrdemServico $os) => self::donoDaOs($os))->filter();
        $dosLancamentos = XpLancamento::withoutGlobalScopes()
            ->where('empresa_id', $empresa->id)->whereDate('competencia', $dia)->distinct()->pluck('usuario_id');

        return $daOs->merge($dosLancamentos)->map(fn ($id) => (int) $id)->unique()->values()->all();
    }

    public static function recalcular(Empresa $empresa, int $usuarioId, string $dia): XpResumoDiario
    {
        $fuso = Fuso::daEmpresa($empresa);
        $oss = self::osDoDia($empresa, $dia)->filter(fn (OrdemServico $os) => self::donoDaOs($os) === $usuarioId && DiaXp::contaNoPossivel($os, $fuso));

        $lancamentos = XpLancamento::withoutGlobalScopes()
            ->with('classificacaoAtual')
            ->where('usuario_id', $usuarioId)
            ->whereDate('competencia', $dia)
            ->get();
        $oficiais = $lancamentos->where('status', XpLancamento::OFICIAL);

        $visitasFeitas = Visita::withoutGlobalScopes()
            ->whereIn('id', $oss->pluck('visita_id')->filter())
            ->where('status', StatusVisita::FINALIZADA)
            ->pluck('id');
        $incompletas = XpLancamento::withoutGlobalScopes()
            ->whereIn('visita_id', $visitasFeitas)
            ->where('status', XpLancamento::OFICIAL)
            ->where('natureza', XpLancamento::PERDA)
            ->whereIn('categoria', ['obrigatorias_incompletas', 'visita_curta'])
            ->groupBy('visita_id')
            ->havingRaw('SUM(pontos) > 0')
            ->pluck('visita_id');

        $naoFeitas = $oss->reject(fn (OrdemServico $os) => $os->visita_id && $visitasFeitas->contains($os->visita_id));
        $justificadas = $lancamentos->where('categoria', 'visita_nao_realizada')
            ->filter(fn (XpLancamento $l) => $l->classificacaoAtual?->justificada)
            ->pluck('ordem_servico_id');
        $protegidas = $naoFeitas->filter(fn (OrdemServico $os) => in_array($os->responsavel_nao_execucao, [ResponsavelNaoExecucao::LOJA->value, ResponsavelNaoExecucao::EMPRESA->value], true)
            || $justificadas->contains($os->id))->count();

        $perdas = $oficiais->where('natureza', XpLancamento::PERDA)
            ->groupBy('categoria')
            ->map(fn (Collection $g) => (int) $g->sum('pontos'))
            ->filter(fn (int $v) => $v !== 0)
            ->all();

        return XpResumoDiario::withoutGlobalScopes()->updateOrCreate(
            ['usuario_id' => $usuarioId, 'dia' => $dia],
            [
                'empresa_id' => $empresa->id,
                'possivel' => (int) $oss->sum('xp_previsto'),
                'realizado' => (int) $oficiais->filter(fn (XpLancamento $l) => $l->natureza !== XpLancamento::PERDA && ! ComposicaoXp::ehIniciativa($l->categoria))->sum('pontos'),
                'iniciativa' => (int) $oficiais->filter(fn (XpLancamento $l) => $l->natureza !== XpLancamento::PERDA && ComposicaoXp::ehIniciativa($l->categoria))->sum('pontos'),
                'pendente' => (int) $lancamentos->where('status', XpLancamento::PENDENTE)->sum('pontos'),
                'perdas' => $perdas,
                'visitas_previstas' => $oss->count(),
                'visitas_feitas' => $visitasFeitas->count(),
                'visitas_completas' => $visitasFeitas->diff($incompletas)->count(),
                'visitas_protegidas' => $protegidas,
                'atualizado_em' => now(),
            ],
        );
    }

    /** Quem responde pela OS: o promotor dela, ou quem fez a visita (OS de fila aberta). */
    public static function donoDaOs(OrdemServico $os): ?int
    {
        if ($os->usuario_id) {
            return (int) $os->usuario_id;
        }
        if ($os->visita_id) {
            return (int) Visita::withoutGlobalScopes()->whereKey($os->visita_id)->value('usuario_id') ?: null;
        }

        return null;
    }
}

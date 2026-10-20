<?php

namespace App\Support\Xp;

use App\Enums\StatusVisita;
use App\Models\Empresa;
use App\Models\OrdemServico;
use App\Models\Supervisor;
use App\Models\Usuario;
use App\Models\Visita;
use App\Models\XpLancamento;
use App\Models\XpResumoDiario;
use App\Support\Fuso;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * O que o promotor vê (docs/88 §12): linguagem de jogo e sempre positiva — "o que faltou pra
 * visita perfeita", nunca "você perdeu". Mesmos números da régua do gestor.
 */
final class PontuacaoPromotorXp
{
    /** @return array<string, mixed> aba "Hoje" (§12.2.1) */
    public static function hoje(Empresa $empresa, Usuario $usuario): array
    {
        $fuso = Fuso::daEmpresa($empresa);
        $dia = now($fuso)->toDateString();
        $resumo = ResumoXp::recalcular($empresa, $usuario->id, $dia);
        $mostraPendente = ParametrosXp::pendenteVisivelPromotor($empresa);

        $oss = ResumoXp::osDoDia($empresa, $dia)
            ->filter(fn (OrdemServico $os) => ResumoXp::donoDaOs($os) === $usuario->id && DiaXp::contaNoPossivel($os, $fuso))
            ->load(['pontoVenda:id,uuid,fantasia', 'classe:id,nome,icone,cor']);
        $ganhosPorVisita = self::ganhoPorVisita($oss->pluck('visita_id')->filter()->all());
        $feitas = Visita::withoutGlobalScopes()->whereIn('id', $oss->pluck('visita_id')->filter())->where('status', StatusVisita::FINALIZADA)->get()->keyBy('id');

        $visitas = $oss->sortBy(fn (OrdemServico $os) => $os->horario_previsto ?? '99:99')->map(function (OrdemServico $os) use ($ganhosPorVisita, $feitas, $fuso) {
            $visita = $os->visita_id ? $feitas->get($os->visita_id) : null;

            return [
                'ordem_servico_id' => $os->uuid,
                'visita_id' => $visita?->uuid,
                'loja' => $os->pontoVenda?->fantasia,
                'horario' => $os->horario_previsto ? substr((string) $os->horario_previsto, 0, 5) : null,
                'quando' => $visita ? self::intervalo($visita, $fuso) : null,
                'classe' => self::classe($os),
                'possivel' => (int) $os->xp_previsto,
                'ganho' => $visita ? ($ganhosPorVisita[$visita->id] ?? 0) : null,
                'feita' => $visita !== null,
            ];
        })->values()->all();

        $iniciativas = self::iniciativas($usuario->id, $dia, $mostraPendente);
        $proxima = collect($visitas)->where('feita', false)->sortByDesc('possivel')->first();

        return [
            'dia' => $dia,
            'total' => $resumo->realizado + $resumo->iniciativa,
            'plano' => $resumo->realizado,
            'possivel' => $resumo->possivel,
            'iniciativa' => $resumo->iniciativa,
            'pendente' => $mostraPendente ? $resumo->pendente : null,
            'ofensiva' => OfensivaXp::dias($empresa, $usuario->id),
            'liga' => self::posicaoNaLiga($empresa, $usuario),
            'visitas' => $visitas,
            'iniciativas' => $iniciativas,
            'dica' => $proxima
                ? "{$proxima['loja']}".($proxima['horario'] ? ", às {$proxima['horario']}" : '').", vale até {$proxima['possivel']} XP."
                : null,
        ];
    }

    /** @return array<string, mixed> aba "Semana" (§12.2.1) */
    public static function semana(Empresa $empresa, Usuario $usuario): array
    {
        $fuso = Fuso::daEmpresa($empresa);
        $hoje = now($fuso)->toDateString();
        ResumoXp::recalcular($empresa, $usuario->id, $hoje);
        $inicio = DiaXp::inicioDaSemana($hoje);
        $anterior = Carbon::parse($inicio)->subWeek()->toDateString();

        $resumos = XpResumoDiario::withoutGlobalScopes()->where('usuario_id', $usuario->id)
            ->whereBetween('dia', [$anterior, Carbon::parse($inicio)->addDays(6)->toDateString()])->get()
            ->keyBy(fn (XpResumoDiario $r) => $r->dia->toDateString());

        $dias = [];
        for ($i = 0; $i < 7; $i++) {
            $d = Carbon::parse($inicio)->addDays($i)->toDateString();
            $r = $resumos->get($d);
            $dias[] = ['dia' => $d, 'xp' => $r ? $r->realizado + $r->iniciativa : 0, 'possivel' => $r?->possivel ?? 0, 'hoje' => $d === $hoje];
        }
        $aproveitamento = function (string $de) use ($resumos) {
            $semana = $resumos->filter(fn ($r, $d) => $d >= $de && $d <= Carbon::parse($de)->addDays(6)->toDateString());
            $pos = (int) $semana->sum('possivel');

            return $pos > 0 ? (int) round($semana->sum('realizado') / $pos * 100) : null;
        };
        $estaSemana = collect($dias);

        return [
            'semana_inicio' => $inicio,
            'total' => (int) $estaSemana->sum('xp'),
            'dias' => $dias,
            'aproveitamento' => $aproveitamento($inicio),
            'aproveitamento_semana_passada' => $aproveitamento($anterior),
            'ofensiva' => OfensivaXp::dias($empresa, $usuario->id),
            'liga' => self::posicaoNaLiga($empresa, $usuario),
            'conquistas' => ConquistasXp::do($empresa, $usuario->id),
        ];
    }

    /**
     * Ranking da equipe (§12.4). O promotor vê o pódio, a própria posição e quem está logo
     * acima; os demais só como contagem. O gestor (`completo`) vê a lista inteira.
     *
     * @return array<string, mixed>
     */
    public static function ranking(Empresa $empresa, Usuario $usuario, string $qual, bool $completo = false, ?int $supervisorId = null): array
    {
        $fuso = Fuso::daEmpresa($empresa);
        $hoje = now($fuso)->toDateString();
        $atual = DiaXp::inicioDaSemana($hoje);
        $semana = $qual === 'passada' ? Carbon::parse($atual)->subWeek()->toDateString() : $atual;
        $grupo = $completo ? $supervisorId : $usuario->supervisor_id;
        $linhas = LigaXp::classificacao($empresa, $grupo, $semana);

        $eu = collect($linhas)->firstWhere('usuario_id', $usuario->id);
        $acima = $eu && $eu['posicao'] > 1 ? $linhas[$eu['posicao'] - 2] : null;
        $publico = fn (array $l) => [
            'posicao' => $l['posicao'], 'nome' => $l['nome'], 'xp' => $l['xp'], 'eu' => $l['usuario_id'] === $usuario->id,
        ] + ($completo ? ['plano' => $l['plano'], 'iniciativa' => $l['iniciativa'], 'possivel' => $l['possivel'], 'visitas_completas' => $l['visitas_completas']] : []);

        $visiveis = $completo
            ? $linhas
            : array_values(array_filter($linhas, fn ($l) => $l['posicao'] <= 3 || $l['usuario_id'] === $usuario->id || ($acima && $l['usuario_id'] === $acima['usuario_id'])));
        $fim = Carbon::parse($semana)->addWeek();
        $supervisor = $grupo ? Supervisor::withoutGlobalScopes()->find($grupo) : null;

        return [
            'semana_inicio' => $semana,
            'fechada' => $qual === 'passada',
            'fecha_em' => $qual === 'passada' ? null : Fuso::instanteLocal($fim->toDateString(), '00:00', $fuso),
            'grupo' => $supervisor ? "Equipe de {$supervisor->nome}" : $empresa->nome_fantasia,
            'minimo_visitas' => ParametrosXp::ligaMinimoVisitas($empresa),
            'participantes' => count($linhas),
            'linhas' => array_map($publico, $visiveis),
            'escondidos' => $completo ? 0 : max(0, count($linhas) - count($visiveis)),
            'eu' => $eu ? [
                'posicao' => $eu['posicao'], 'xp' => $eu['xp'], 'plano' => $eu['plano'], 'iniciativa' => $eu['iniciativa'],
                'aproveitamento' => $eu['possivel'] > 0 ? (int) round($eu['plano'] / $eu['possivel'] * 100) : null,
            ] : null,
            'alvo' => $eu && $acima ? ['nome' => $acima['nome'], 'faltam' => $acima['xp'] - $eu['xp'] + 1, 'de' => $eu['xp'], 'ate' => $acima['xp']] : null,
        ];
    }

    /** @return array<string, mixed>|null posição do promotor na liga desta semana (chip) */
    public static function posicaoNaLiga(Empresa $empresa, Usuario $usuario): ?array
    {
        if (! ParametrosXp::rankingHabilitado($empresa)) {
            return null;
        }
        $semana = DiaXp::inicioDaSemana(now(Fuso::daEmpresa($empresa))->toDateString());
        $linhas = LigaXp::classificacao($empresa, $usuario->supervisor_id, $semana);
        $eu = collect($linhas)->firstWhere('usuario_id', $usuario->id);
        if (! $eu) {
            return null;
        }
        $acima = $eu['posicao'] > 1 ? $linhas[$eu['posicao'] - 2] : null;

        return [
            'posicao' => $eu['posicao'],
            'participantes' => count($linhas),
            'faltam_para_subir' => $acima ? $acima['xp'] - $eu['xp'] + 1 : null,
            'nome_acima' => $acima ? explode(' ', $acima['nome'])[0] : null,
        ];
    }

    /** @return array<string, mixed> "Pontuação da visita" (§12.2.1) — promotor e gestor */
    public static function visita(Empresa $empresa, Visita $visita): array
    {
        $visita->loadMissing(['ordemServico.classe', 'pontoVenda', 'registros.tipoRegistro.campos', 'registros.motivoResolucao']);
        $calculo = CalculoVisitaXp::para($visita, $empresa);
        $fuso = Fuso::daEmpresa($empresa);
        $lancamentos = XpLancamento::withoutGlobalScopes()->with('criadoPor:id,nome')->where('visita_id', $visita->id)->orderBy('id')->get();
        $oficial = $lancamentos->where('status', XpLancamento::OFICIAL)->where('natureza', '!=', XpLancamento::PERDA);
        $total = (int) $oficial->filter(fn ($l) => ! ComposicaoXp::ehIniciativa($l->categoria))->sum('pontos');
        $mostraPendente = ParametrosXp::pendenteVisivelPromotor($empresa);

        $ajustes = $lancamentos->whereIn('natureza', [XpLancamento::AJUSTE, XpLancamento::ESTORNO])
            ->filter(fn ($l) => $l->criado_por_id !== null)
            ->groupBy(fn ($l) => $l->created_at?->format('Y-m-d H:i').'|'.$l->motivo)
            ->map(fn (Collection $g) => [
                'pontos' => (int) $g->sum('pontos'),
                'motivo' => $g->first()->motivo,
                'por' => $g->first()->criadoPor?->nome,
                'em' => $g->first()->created_at,
            ])->values()->all();

        return [
            'visita_id' => $visita->uuid,
            'loja' => $visita->pontoVenda?->fantasia,
            'quando' => self::intervalo($visita, $fuso),
            'dia' => $calculo->dia,
            'classe' => $visita->ordemServico ? self::classe($visita->ordemServico) : null,
            'possivel' => $calculo->xpBase,
            'total' => $total,
            'faltou' => max(0, $calculo->xpBase - $total),
            'completa' => $calculo->completa,
            'partes' => $calculo->partes,
            'iniciativas' => array_values(array_filter(array_map(fn ($i) => $i + ['visivel' => $i['situacao'] !== 'pendente' || $mostraPendente], $calculo->iniciativas), fn ($i) => $i['visivel'])),
            'ajustes' => $ajustes,
            'fora_da_agenda' => $visita->ordem_servico_id === null,
        ];
    }

    /** @return array<int, int> visita_id => XP do plano já oficial */
    private static function ganhoPorVisita(array $visitaIds): array
    {
        return XpLancamento::withoutGlobalScopes()
            ->whereIn('visita_id', $visitaIds)
            ->where('status', XpLancamento::OFICIAL)
            ->where('natureza', '!=', XpLancamento::PERDA)
            ->whereNotIn('categoria', [ComposicaoXp::INICIATIVA, ComposicaoXp::BONUS_ACAO])
            ->groupBy('visita_id')
            ->selectRaw('visita_id, SUM(pontos) AS total')
            ->pluck('total', 'visita_id')
            ->map(fn ($v) => (int) $v)
            ->all();
    }

    /** @return list<array<string, mixed>> */
    private static function iniciativas(int $usuarioId, string $dia, bool $mostraPendente): array
    {
        return XpLancamento::withoutGlobalScopes()
            ->with(['registro.tipoRegistro:id,descricao', 'visita.pontoVenda:id,fantasia'])
            ->where('usuario_id', $usuarioId)
            ->whereDate('competencia', $dia)
            ->whereIn('categoria', [ComposicaoXp::INICIATIVA, ComposicaoXp::BONUS_ACAO])
            ->whereIn('status', $mostraPendente ? [XpLancamento::OFICIAL, XpLancamento::PENDENTE] : [XpLancamento::OFICIAL])
            ->where('natureza', '!=', XpLancamento::PERDA)
            ->get()
            ->groupBy(fn (XpLancamento $l) => $l->visita_registro_id.'|'.$l->categoria.'|'.$l->status)
            ->map(function (Collection $g) {
                $l = $g->first();
                $descricao = $l->registro?->tipoRegistro?->descricao ?? 'Iniciativa';

                return [
                    'descricao' => $l->categoria === ComposicaoXp::BONUS_ACAO ? "{$descricao} virou ação" : $descricao,
                    'loja' => $l->visita?->pontoVenda?->fantasia,
                    'pontos' => (int) $g->sum('pontos'),
                    'situacao' => $l->status === XpLancamento::PENDENTE ? 'pendente' : 'ok',
                ];
            })
            ->filter(fn ($i) => $i['pontos'] > 0)
            ->values()->all();
    }

    private static function intervalo(Visita $visita, string $fuso): ?string
    {
        if (! $visita->inicio_data) {
            return null;
        }
        $inicio = $visita->inicio_data->copy()->setTimezone($fuso)->format('H:i');
        $fim = $visita->fim_data?->copy()->setTimezone($fuso)->format('H:i');

        return $fim ? "{$inicio} às {$fim}" : $inicio;
    }

    /** @return array{nome: string, icone: ?string, cor: ?string}|null */
    private static function classe(OrdemServico $os): ?array
    {
        $classe = $os->relationLoaded('classe') ? $os->classe : $os->classe()->first();

        return $classe ? ['nome' => $classe->nome, 'icone' => $classe->icone, 'cor' => $classe->cor] : null;
    }
}

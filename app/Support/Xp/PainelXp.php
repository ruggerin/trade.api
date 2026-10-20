<?php

namespace App\Support\Xp;

use App\Enums\ResponsavelNaoExecucao;
use App\Models\Empresa;
use App\Models\OrdemServico;
use App\Models\Usuario;
use App\Models\VisitaRegistro;
use App\Models\XpLancamento;
use App\Models\XpResumoDiario;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Régua da operação no admin (docs/88 §11): possível × realizado, onde ficaram as perdas, os
 * promotores, a evolução, a qualidade do planejamento e o valor gerado pela iniciativa. Mesmos
 * números que o promotor vê, com a linguagem de gestor.
 */
final class PainelXp
{
    /** @return array{0: string, 1: string} primeiro e último dia do período */
    public static function periodo(string $dia, string $periodo): array
    {
        // Mês corrente do dia escolhido — a visão que fecha o resultado da operação.
        if ($periodo === 'mes') {
            return [Carbon::parse($dia)->startOfMonth()->toDateString(), Carbon::parse($dia)->endOfMonth()->toDateString()];
        }
        if ($periodo === 'semana') {
            $inicio = DiaXp::inicioDaSemana($dia);

            return [$inicio, Carbon::parse($inicio)->addDays(6)->toDateString()];
        }

        return [$dia, $dia];
    }

    /** Resumos do período pros promotores do escopo — dia corrente e anterior recalculados na hora. */
    public static function resumos(Empresa $empresa, Collection $promotores, string $inicio, string $fim): Collection
    {
        $hoje = now(DiaXp::fusoDa($empresa))->toDateString();
        $ontem = Carbon::parse($hoje)->subDay()->toDateString();
        foreach ([$ontem, $hoje] as $dia) {
            if ($dia < $inicio || $dia > $fim) {
                continue;
            }
            $usuarios = array_intersect(ResumoXp::usuariosDoDia($empresa, $dia), $promotores->pluck('id')->all());
            foreach ($usuarios as $usuarioId) {
                ResumoXp::recalcular($empresa, (int) $usuarioId, $dia);
            }
        }

        return XpResumoDiario::withoutGlobalScopes()
            ->whereIn('usuario_id', $promotores->pluck('id'))
            ->whereBetween('dia', [$inicio, $fim])
            ->get();
    }

    /** @return array<string, mixed> */
    public static function regua(Empresa $empresa, string $dia, string $periodo, ?int $supervisorId): array
    {
        [$inicio, $fim] = self::periodo($dia, $periodo);
        $promotores = EquipeXp::promotores($empresa, $supervisorId);
        $resumos = self::resumos($empresa, $promotores, $inicio, $fim);

        $possivel = (int) $resumos->sum('possivel');
        $realizado = (int) $resumos->sum('realizado');
        $perdasPorCategoria = [];
        foreach ($resumos as $r) {
            foreach ($r->perdas ?? [] as $categoria => $pontos) {
                $perdasPorCategoria[$categoria] = ($perdasPorCategoria[$categoria] ?? 0) + (int) $pontos;
            }
        }
        $totalPerdas = array_sum(array_filter($perdasPorCategoria, fn ($p) => $p > 0));
        $ocorrencias = self::ocorrencias($empresa, $inicio, $fim, $supervisorId)->groupBy('categoria');

        $perdas = collect($perdasPorCategoria)
            ->filter(fn ($p) => $p > 0)
            ->sortDesc()
            ->map(fn ($pontos, $categoria) => [
                'categoria' => $categoria,
                'rotulo' => ComposicaoXp::ROTULOS_PERDA[$categoria] ?? $categoria,
                'xp' => $pontos,
                'visitas' => $ocorrencias->get($categoria, collect())->count(),
                'percentual' => $totalPerdas > 0 ? (int) round($pontos / $totalPerdas * 100) : 0,
            ])->values()->all();

        $porPromotor = $resumos->groupBy('usuario_id');
        $linhas = $promotores
            ->filter(fn (Usuario $u) => $porPromotor->has($u->id))
            ->map(function (Usuario $u) use ($porPromotor, $empresa) {
                $r = $porPromotor->get($u->id);
                $pos = (int) $r->sum('possivel');
                $real = (int) $r->sum('realizado');

                return [
                    'usuario' => self::pessoa($u),
                    'possivel' => $pos,
                    'realizado' => $real,
                    'aproveitamento' => $pos > 0 ? (int) round($real / $pos * 100) : null,
                    'iniciativa' => (int) $r->sum('iniciativa'),
                    'pendente' => (int) $r->sum('pendente'),
                    'visitas_previstas' => (int) $r->sum('visitas_previstas'),
                    'visitas_feitas' => (int) $r->sum('visitas_feitas'),
                    'ofensiva' => OfensivaXp::dias($empresa, $u->id),
                ];
            })
            ->sortByDesc('realizado')->values()->all();

        $fila = self::filaAvaliacao($empresa, $supervisorId);

        return [
            'periodo' => ['tipo' => $periodo, 'inicio' => $inicio, 'fim' => $fim],
            'congelado_em' => DiaXp::HORA_CONGELAMENTO,
            'modo_previa' => ! ParametrosXp::visivelPromotor($empresa),
            'promotores_no_escopo' => $promotores->count(),
            'promotores_com_os' => count($linhas),
            'visitas_previstas' => (int) $resumos->sum('visitas_previstas'),
            'kpis' => [
                'possivel' => $possivel,
                'realizado' => $realizado,
                'aproveitamento' => $possivel > 0 ? (int) round($realizado / $possivel * 100) : null,
                'iniciativa' => (int) $resumos->sum('iniciativa'),
                'pendente' => (int) $resumos->sum('pendente'),
                'aguardando_avaliacao' => $fila['total'],
            ],
            'perdas' => $perdas,
            'promotores' => $linhas,
            'fila_avaliacao' => $fila['itens'],
        ];
    }

    /**
     * Uma linha por perda (visita ou OS × categoria), com a última classificação.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public static function ocorrencias(Empresa $empresa, string $inicio, string $fim, ?int $supervisorId, ?string $categoria = null): Collection
    {
        $promotores = EquipeXp::promotores($empresa, $supervisorId)->pluck('id');
        $lancamentos = XpLancamento::withoutGlobalScopes()
            ->with(['classificacoes.motivo', 'classificacoes.planoAcao', 'classificacoes.criadoPor', 'usuario:id,uuid,nome,foto_path,updated_at',
                'ordemServico.pontoVenda:id,uuid,fantasia', 'ordemServico.classe:id,nome,icone,cor', 'ordemServico.motivoCancelamento', 'visita:id,uuid,inicio_data'])
            ->where('empresa_id', $empresa->id)
            ->whereIn('usuario_id', $promotores)
            ->where('status', XpLancamento::OFICIAL)
            ->where('natureza', XpLancamento::PERDA)
            ->whereBetween('competencia', [$inicio, $fim])
            ->when($categoria, fn ($q) => $q->where('categoria', $categoria))
            ->orderBy('id')
            ->get();

        return $lancamentos
            ->groupBy(fn (XpLancamento $l) => ($l->visita_id ? "v{$l->visita_id}" : "o{$l->ordem_servico_id}").'|'.$l->categoria)
            ->map(function (Collection $grupo) {
                /** @var XpLancamento $primeiro */
                $primeiro = $grupo->first();
                $classificacao = $grupo->flatMap->classificacoes->sortBy('id')->last();
                $os = $primeiro->ordemServico;

                return [
                    'id' => $primeiro->uuid,
                    'categoria' => $primeiro->categoria,
                    'rotulo' => ComposicaoXp::ROTULOS_PERDA[$primeiro->categoria] ?? $primeiro->categoria,
                    'xp' => (int) $grupo->sum('pontos'),
                    'dia' => $primeiro->competencia->toDateString(),
                    'promotor' => $primeiro->usuario ? self::pessoa($primeiro->usuario) : null,
                    'loja' => $os?->pontoVenda ? ['id' => $os->pontoVenda->uuid, 'fantasia' => $os->pontoVenda->fantasia] : null,
                    'classe' => $os?->classe ? ['nome' => $os->classe->nome, 'icone' => $os->classe->icone, 'cor' => $os->classe->cor] : null,
                    'ordem_servico_id' => $os?->uuid,
                    'visita_id' => $primeiro->visita?->uuid,
                    'responsavel_nao_execucao' => $os?->responsavel_nao_execucao,
                    'motivo_nao_execucao' => $os?->motivoCancelamento?->descricao ?? $os?->motivo_cancelamento_texto,
                    'classificacao' => $classificacao ? [
                        'justificada' => $classificacao->justificada,
                        'causa' => $classificacao->motivo ? ['id' => $classificacao->motivo->uuid, 'descricao' => $classificacao->motivo->descricao] : null,
                        'comentario' => $classificacao->comentario,
                        'plano_acao' => $classificacao->planoAcao ? ['id' => $classificacao->planoAcao->uuid, 'titulo' => $classificacao->planoAcao->titulo] : null,
                        'por' => $classificacao->criadoPor?->nome,
                        'em' => $classificacao->created_at,
                    ] : null,
                ];
            })
            ->filter(fn ($o) => $o['xp'] > 0)
            ->sortByDesc('xp')
            ->values();
    }

    /** @return list<array{semana_inicio: string, possivel: int, realizado: int, aproveitamento: ?int}> */
    public static function evolucao(Empresa $empresa, ?int $supervisorId, int $semanas = 8): array
    {
        $promotores = EquipeXp::promotores($empresa, $supervisorId);
        $atual = DiaXp::inicioDaSemana(now(DiaXp::fusoDa($empresa))->toDateString());
        $linhas = [];
        for ($i = $semanas - 1; $i >= 0; $i--) {
            $inicio = Carbon::parse($atual)->subWeeks($i)->toDateString();
            $fim = Carbon::parse($inicio)->addDays(6)->toDateString();
            $r = XpResumoDiario::withoutGlobalScopes()->whereIn('usuario_id', $promotores->pluck('id'))->whereBetween('dia', [$inicio, $fim]);
            $pos = (int) (clone $r)->sum('possivel');
            $real = (int) (clone $r)->sum('realizado');
            $linhas[] = ['semana_inicio' => $inicio, 'possivel' => $pos, 'realizado' => $real, 'aproveitamento' => $pos > 0 ? (int) round($real / $pos * 100) : null];
        }

        return $linhas;
    }

    /** Quantos períodos o comparativo mostra em cada escala. */
    public const COMPARATIVO_PERIODOS = ['mes' => 6, 'semana' => 8, 'dia' => 14];

    /**
     * Comparativo período a período (mês, semana ou dia): os totais de cada período, a variação
     * contra o anterior e o aproveitamento de cada promotor (quem melhorou, quem caiu). Com
     * `$usuarioId`, só aquele promotor. O período corrente aparece como "em andamento".
     *
     * @return array{granularidade: string, periodos: list<array<string, mixed>>, promotores: list<array<string, mixed>>}
     */
    public static function comparativo(Empresa $empresa, ?int $supervisorId, string $granularidade = 'mes', ?int $usuarioId = null): array
    {
        $granularidade = array_key_exists($granularidade, self::COMPARATIVO_PERIODOS) ? $granularidade : 'mes';
        $promotores = EquipeXp::promotores($empresa, $supervisorId)
            ->when($usuarioId, fn ($c) => $c->where('id', $usuarioId)->values());
        $hoje = now(DiaXp::fusoDa($empresa))->startOfDay();

        // Início de cada período, do mais antigo pro atual.
        $quantidade = self::COMPARATIVO_PERIODOS[$granularidade];
        $inicioDo = fn (Carbon $d) => match ($granularidade) {
            'mes' => $d->copy()->startOfMonth(),
            'semana' => $d->copy()->startOfWeek(Carbon::MONDAY),
            default => $d->copy()->startOfDay(),
        };
        $passo = fn (Carbon $d, int $n) => match ($granularidade) {
            'mes' => $d->copy()->subMonthsNoOverflow($n),
            'semana' => $d->copy()->subWeeks($n),
            default => $d->copy()->subDays($n),
        };
        $atual = $inicioDo($hoje);
        $inicios = [];
        for ($n = $quantidade - 1; $n >= 0; $n--) {
            $inicios[] = $passo($atual, $n);
        }
        $fimJanela = match ($granularidade) {
            'mes' => $atual->copy()->endOfMonth(),
            'semana' => $atual->copy()->addDays(6),
            default => $atual->copy(),
        };
        $chaveDe = fn (Carbon $dia) => $inicioDo($dia)->toDateString();

        $resumos = XpResumoDiario::withoutGlobalScopes()
            ->whereIn('usuario_id', $promotores->pluck('id'))
            ->whereBetween('dia', [$inicios[0]->toDateString(), $fimJanela->toDateString()])
            ->get();
        $porPeriodo = $resumos->groupBy(fn (XpResumoDiario $r) => $chaveDe($r->dia));

        $pct = fn (int $real, int $pos) => $pos > 0 ? (int) round($real / $pos * 100) : null;
        $periodos = [];
        $anterior = null;
        foreach ($inicios as $idx => $inicio) {
            $chave = $inicio->toDateString();
            $doPeriodo = $porPeriodo->get($chave, collect());
            $pos = (int) $doPeriodo->sum('possivel');
            $real = (int) $doPeriodo->sum('realizado');
            $linha = [
                'inicio' => $chave,
                'possivel' => $pos,
                'realizado' => $real,
                'aproveitamento' => $pct($real, $pos),
                'iniciativa' => (int) $doPeriodo->sum('iniciativa'),
                'perdas' => (int) $doPeriodo->sum(fn (XpResumoDiario $r) => array_sum(array_filter($r->perdas ?? [], fn ($v) => $v > 0))),
                'visitas_previstas' => (int) $doPeriodo->sum('visitas_previstas'),
                'visitas_feitas' => (int) $doPeriodo->sum('visitas_feitas'),
                'em_andamento' => $idx === count($inicios) - 1,
            ];
            // Variação em pontos percentuais do aproveitamento e em % da iniciativa.
            $linha['variacao_aproveitamento'] = $anterior && $anterior['aproveitamento'] !== null && $linha['aproveitamento'] !== null
                ? $linha['aproveitamento'] - $anterior['aproveitamento'] : null;
            $linha['variacao_iniciativa'] = $anterior && $anterior['iniciativa'] > 0
                ? (int) round(($linha['iniciativa'] - $anterior['iniciativa']) / $anterior['iniciativa'] * 100) : null;
            $periodos[] = $linha;
            $anterior = $linha;
        }

        $porPromotor = [];
        $todos = $resumos->groupBy('usuario_id');
        foreach ($promotores as $u) {
            $deles = $todos->get($u->id);
            if (! $deles) {
                continue;
            }
            $agrupado = $deles->groupBy(fn (XpResumoDiario $r) => $chaveDe($r->dia));
            $porPromotor[] = [
                'usuario' => self::pessoa($u),
                'periodos' => array_map(function (array $per) use ($agrupado, $pct) {
                    $r = $agrupado->get($per['inicio'], collect());

                    return [
                        'inicio' => $per['inicio'],
                        'aproveitamento' => $pct((int) $r->sum('realizado'), (int) $r->sum('possivel')),
                        'xp' => (int) $r->sum('realizado') + (int) $r->sum('iniciativa'),
                    ];
                }, $periodos),
            ];
        }

        return ['granularidade' => $granularidade, 'periodos' => $periodos, 'promotores' => $porPromotor];
    }

    /**
     * Qualidade do planejamento (§11.1): o gestor também é medido.
     *
     * @return array<string, mixed>
     */
    public static function planejamento(Empresa $empresa, string $dia, ?int $supervisorId): array
    {
        [$inicio, $fim] = self::periodo($dia, 'semana');
        $fuso = DiaXp::fusoDa($empresa);
        $promotores = EquipeXp::promotores($empresa, $supervisorId)->pluck('id')->all();
        $oss = collect();
        for ($d = Carbon::parse($inicio); $d->toDateString() <= $fim; $d->addDay()) {
            $oss = $oss->merge(ResumoXp::osDoDia($empresa, $d->toDateString())
                ->filter(fn (OrdemServico $os) => in_array(ResumoXp::donoDaOs($os), $promotores, true) && DiaXp::contaNoPossivel($os, $fuso)));
        }
        $total = $oss->count();
        // "Dia e horário": OS de um dia só (não janela) com horário marcado. Compara as datas
        // como gravadas — o prazo da agenda ainda vai pro banco na hora "de parede" (doc 50).
        $comHorario = $oss->filter(fn (OrdemServico $os) => $os->horario_previsto
            && $os->prazo_inicio?->toDateString() === $os->prazo_fim?->toDateString())->count();

        $naoRealizadas = XpLancamento::withoutGlobalScopes()->with('ordemServico:id,responsavel_nao_execucao')
            ->whereIn('usuario_id', $promotores)->where('categoria', 'visita_nao_realizada')->where('status', XpLancamento::OFICIAL)
            ->whereBetween('competencia', [$inicio, $fim])->get();
        $perdaTotal = (int) $naoRealizadas->sum('pontos');
        $perdaEmpresa = (int) $naoRealizadas->filter(fn ($l) => $l->ordemServico?->responsavel_nao_execucao === ResponsavelNaoExecucao::EMPRESA->value)->sum('pontos');

        $cargas = $oss->groupBy(fn (OrdemServico $os) => ResumoXp::donoDaOs($os))->map(fn ($g) => (int) $g->sum('xp_previsto'));
        $media = $cargas->count() > 0 ? $cargas->avg() : 0;
        $desvio = null;
        if ($media > 0) {
            $maior = $cargas->sortByDesc(fn ($v) => abs($v - $media))->keys()->first();
            $desvio = [
                'promotor' => Usuario::withoutGlobalScopes()->whereKey($maior)->value('nome'),
                'percentual' => (int) round(($cargas[$maior] - $media) / $media * 100),
            ];
        }

        return [
            'semana_inicio' => $inicio,
            'os_total' => $total,
            'os_com_dia_e_horario' => $total > 0 ? (int) round($comHorario / $total * 100) : null,
            // Duração revisada pelo gestor depende do planejador do doc 87 (ainda não existe).
            'duracao_revisada' => null,
            'perdas_responsavel_empresa' => $perdaTotal > 0 ? (int) round($perdaEmpresa / $perdaTotal * 100) : null,
            'carga_mais_desequilibrada' => $desvio,
        ];
    }

    /**
     * Valor gerado pelo campo no mês (§11.2): o que a iniciativa trouxe.
     *
     * @return array<string, mixed>
     */
    public static function valorGerado(Empresa $empresa, string $mes): array
    {
        $inicio = Carbon::parse("{$mes}-01")->toDateString();
        $fim = Carbon::parse($inicio)->endOfMonth()->toDateString();
        $lancamentos = XpLancamento::withoutGlobalScopes()
            ->with('registro.tipoRegistro:id,uuid,descricao')
            ->where('empresa_id', $empresa->id)
            ->where('status', XpLancamento::OFICIAL)
            ->whereIn('categoria', [ComposicaoXp::INICIATIVA, ComposicaoXp::BONUS_ACAO])
            ->whereBetween('competencia', [$inicio, $fim])
            ->get();

        $porFormulario = $lancamentos->groupBy(fn (XpLancamento $l) => $l->registro?->tipoRegistro?->uuid ?? '—')
            ->map(function (Collection $g) {
                $tipo = $g->first()->registro?->tipoRegistro;
                $registros = $g->where('categoria', ComposicaoXp::INICIATIVA)->pluck('visita_registro_id')->unique()->count();

                return [
                    'formulario' => $tipo ? ['id' => $tipo->uuid, 'descricao' => $tipo->descricao] : null,
                    'registros' => $registros,
                    'viraram_acao' => $g->where('categoria', ComposicaoXp::BONUS_ACAO)->pluck('visita_registro_id')->unique()->count(),
                    'xp' => (int) $g->sum('pontos'),
                ];
            })->sortByDesc('registros')->values()->all();

        $planosDeOcorrencia = \App\Models\XpOcorrenciaClassificacao::withoutGlobalScopes()
            ->where('empresa_id', $empresa->id)->whereNotNull('plano_acao_id')
            ->whereBetween('created_at', [Carbon::parse($inicio)->startOfDay(), Carbon::parse($fim)->endOfDay()])
            ->distinct('plano_acao_id')->count('plano_acao_id');

        return [
            'mes' => $mes,
            'formularios' => $porFormulario,
            'total_registros' => array_sum(array_column($porFormulario, 'registros')),
            'total_viraram_acao' => array_sum(array_column($porFormulario, 'viraram_acao')),
            'planos_de_ocorrencias' => $planosDeOcorrencia,
        ];
    }

    /** Promotor com a foto do cadastro (mesma rota autenticada do UsuarioResource). */
    public static function pessoa(Usuario $u): array
    {
        return [
            'id' => $u->uuid,
            'nome' => $u->nome,
            'foto_url' => $u->foto_path ? url("/api/usuarios/{$u->uuid}/foto").'?v='.$u->updated_at?->timestamp : null,
        ];
    }

    /** @return array{total: int, itens: list<array<string, mixed>>} */
    public static function filaAvaliacao(Empresa $empresa, ?int $supervisorId, int $limite = 3): array
    {
        $promotores = EquipeXp::promotores($empresa, $supervisorId)->pluck('id');
        $query = VisitaRegistro::query()
            ->where('avaliacao_status', CalculoVisitaXp::STATUS_PENDENTE)
            ->whereNull('cancelado_em')
            ->whereHas('visita', fn ($q) => $q->withoutGlobalScopes()->where('empresa_id', $empresa->id)->whereIn('usuario_id', $promotores));
        $total = (clone $query)->count();
        $itens = $query->with(['tipoRegistro:id,uuid,descricao,xp_iniciativa', 'visita' => fn ($q) => $q->withoutGlobalScopes()->with(['pontoVenda:id,uuid,fantasia', 'usuario:id,uuid,nome'])])
            ->orderBy('created_at')->limit($limite)->get()
            ->map(fn (VisitaRegistro $r) => [
                'id' => $r->uuid,
                'visita_id' => $r->visita?->uuid,
                'formulario' => $r->tipoRegistro?->descricao,
                'loja' => $r->visita?->pontoVenda?->fantasia,
                'promotor' => $r->visita?->usuario?->nome,
                'criado_em' => $r->created_at,
                'xp' => (int) ($r->tipoRegistro?->xp_iniciativa ?? 0),
            ])->all();

        return ['total' => $total, 'itens' => $itens];
    }
}

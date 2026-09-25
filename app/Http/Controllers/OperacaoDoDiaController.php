<?php

namespace App\Http\Controllers;

use App\Enums\StatusOrdemServico;
use App\Enums\UserType;
use App\Models\Empresa;
use App\Models\OrdemServico;
use App\Models\VisitaRegistro;
use App\Support\OperacaoDoDia as SuporteOperacaoDoDia;
use App\Support\Rastreamento;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Endpoint agregador único do painel "Operação do Dia" (docs/32-PAINEL-OPERACAO-DO-DIA.md,
 * Fase 1 item 5) — KPIs do topo, lista "Equipe em Campo" e "Fila de Ações" num payload só, mesmo
 * racional de AtividadeController::index: volume pequeno (uma empresa, um dia), não compensa
 * várias chamadas do front só pra montar 1 tela.
 */
class OperacaoDoDiaController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $usuario = $request->user();
        if (! in_array($usuario->user_type, [UserType::ADMIN, UserType::GESTOR], true)) {
            abort(403, 'A Operação do Dia é só para ADMIN/GESTOR.');
        }

        $request->validate([
            // Sem 'after' pro futuro aqui — "operação do dia" de um dia que ainda não chegou não
            // tem o que mostrar (nenhuma OS/visita existiria ainda pra ele); rejeitado abaixo com
            // a mesma mensagem de validação, não um 200 silenciosamente vazio.
            'data' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $empresa = $usuario->empresa;
        $dia = $request->filled('data') ? Carbon::parse($request->string('data'))->startOfDay() : now();

        if ($dia->isFuture()) {
            abort(422, 'A Operação do Dia não existe pra uma data futura.');
        }

        // "Histórico" = qualquer dia que não seja hoje. Um punhado de conceitos aqui só fazem
        // sentido "agora" (sinal de GPS ao vivo, fila de ações, rupturas ainda abertas) — eles
        // continuam sempre no estado atual, nunca filtrados por `$dia` (ver kpis()/filaAcoes()/
        // rupturas_por_sku abaixo); o front esconde essas seções quando `historico` é true, pra
        // não parecer que são "daquele dia".
        $historico = ! $dia->isToday();
        // Blocos ainda "em aberto" (visita ATUAL, OS pendente sem visita) e a comparação de
        // atraso precisam de um teto — hoje é "agora" de verdade; num dia passado, o teto é o
        // fim daquele próprio dia (senão uma visita esquecida aberta desde ontem esticaria a
        // barra do Gantt até a hora atual de hoje, sem nenhum sentido visual).
        $agora = $historico ? $dia->copy()->endOfDay() : now();
        $janelaSinal = now()->subMinutes(Rastreamento::JANELA_ATIVO_MINUTOS);

        $porPromotor = SuporteOperacaoDoDia::statusPorPromotor($empresa, $dia);
        $rupturasAbertas = $this->rupturasAbertasPorPromotor($empresa);
        $tolerancia = SuporteOperacaoDoDia::toleranciaAtrasoMinutos($empresa);

        $equipe = $porPromotor->map(function (array $linha) use ($janelaSinal, $rupturasAbertas, $agora, $tolerancia, $historico) {
            $promotor = $linha['usuario'];
            $visita = $linha['visita_aberta'];
            $ordens = $linha['ordens'];
            $semSinal = ! $historico
                && (! $promotor->ultima_localizacao_em || $promotor->ultima_localizacao_em->lt($janelaSinal));

            return [
                'usuario' => [
                    'id' => $promotor->uuid,
                    'nome' => $promotor->nome,
                    'foto_url' => $promotor->foto_path ? url("/api/usuarios/{$promotor->uuid}/foto") : null,
                ],
                'status' => $linha['status'],
                'ponto_venda_atual' => $visita?->pontoVenda
                    ? ['id' => $visita->pontoVenda->uuid, 'fantasia' => $visita->pontoVenda->fantasia]
                    : null,
                'checkin_em' => $visita?->inicio_data,
                'visitas' => [
                    'feitas' => $ordens->where('status', StatusOrdemServico::CONCLUIDA)->count(),
                    'total' => $ordens->count(),
                ],
                'rupturas' => $rupturasAbertas->get($promotor->id, 0),
                'ultima_localizacao_em' => $promotor->ultima_localizacao_em,
                'sem_sinal' => $semSinal && $linha['status'] !== SuporteOperacaoDoDia::STATUS_ENCERRADO,
                'blocos_jornada' => $this->blocosJornada($ordens, $agora, $tolerancia),
            ];
        })->sortBy(fn ($linha) => $linha['usuario']['nome'])->values();

        return response()->json([
            'data' => $dia->toDateString(),
            'historico' => $historico,
            'jornada' => SuporteOperacaoDoDia::jornada($empresa),
            'kpis' => $this->kpis($empresa, $porPromotor, $equipe, $dia),
            'equipe' => $equipe,
            // Sempre o estado atual — nunca "daquele dia" (ver comentário acima de $historico).
            'fila_acoes' => $historico ? [] : $this->filaAcoes($empresa, $porPromotor, $agora),
            'rupturas_por_sku' => $historico ? [] : SuporteOperacaoDoDia::rupturasAbertasPorSku($empresa)->map(fn ($linha) => [
                'produto' => [
                    'id' => $linha['produto']->uuid,
                    'descricao' => $linha['produto']->descricao,
                    'codigo_barras' => $linha['produto']->codigo_barras,
                ],
                'pdvs' => $linha['pdvs'],
                'desde' => $linha['desde'],
            ])->values(),
        ]);
    }

    /**
     * @param  Collection<int, array{usuario: \App\Models\Usuario, status: string, visita_aberta: mixed, ordens: Collection}>  $porPromotor
     */
    private function kpis(Empresa $empresa, Collection $porPromotor, Collection $equipe, Carbon $dia): array
    {
        $historico = ! $dia->isToday();
        $ordensHoje = $porPromotor->flatMap(fn ($linha) => $linha['ordens']);
        $planejadas = $ordensHoje->whereNotIn('status', [StatusOrdemServico::CANCELADA, StatusOrdemServico::AGUARDANDO_APROVACAO]);

        return [
            'visitas_realizadas' => [
                'feitas' => $planejadas->where('status', StatusOrdemServico::CONCLUIDA)->count(),
                'total' => $planejadas->count(),
            ],
            'em_campo' => [
                'atual' => $porPromotor->where('status', SuporteOperacaoDoDia::STATUS_NO_PDV)->count(),
                'total' => $porPromotor->count(),
                'encerrados' => $porPromotor->where('status', SuporteOperacaoDoDia::STATUS_ENCERRADO)->count(),
            ],
            'atrasados' => $porPromotor->where('status', SuporteOperacaoDoDia::STATUS_ATRASADO)->count(),
            // Sempre o estado atual, nunca "daquele dia" — ver comentário de $historico em index().
            'sem_sinal' => $historico ? 0 : $equipe->where('sem_sinal', true)->count(),
            'rupturas_abertas' => $historico ? ['total' => 0, 'pdvs' => 0] : $this->contagemRupturasAbertas($empresa),
            'formularios_emitidos' => SuporteOperacaoDoDia::formulariosEmitidosHoje($empresa, $dia),
        ];
    }

    /**
     * Blocos pro Gantt "Jornada" (Fase 3) — um por OS do promotor hoje. OS com visita vinculada
     * vira bloco real (FEITA se a visita já fechou, ATUAL se ainda está aberta, `fim` = agora
     * nesse caso). OS ainda pendente (sem visita) vira um bloco pequeno e nominal a partir do
     * `horario_previsto` — 45 min se ainda dentro da tolerância (PREVISTA), 15 min se já passou
     * (ATRASO_INICIO); não é a duração real (ainda não existe), só um marcador visual.
     *
     * @return list<array{inicio: Carbon, fim: Carbon, status: string}>
     */
    private function blocosJornada(Collection $ordens, Carbon $agora, int $tolerancia): array
    {
        $blocos = [];

        foreach ($ordens as $os) {
            /** @var OrdemServico $os */
            $visita = $os->visita;
            // A visita nasce vinculada à mesma loja da OS (ver VisitaController::store) — usar a
            // da própria OS evita depender de `visita.pontoVenda` estar eager-loaded aqui (só
            // `pontoVenda`/`visita`/`usuario` vêm carregados de OperacaoDoDia::statusPorPromotor).
            $pontoVenda = $os->pontoVenda ? ['id' => $os->pontoVenda->uuid, 'fantasia' => $os->pontoVenda->fantasia] : null;

            if ($visita) {
                $blocos[] = [
                    'inicio' => $visita->inicio_data,
                    'fim' => $visita->fim_data ?? $agora,
                    'status' => $visita->fim_data ? 'FEITA' : 'ATUAL',
                    'ponto_venda' => $pontoVenda,
                    'visita_id' => $visita->uuid,
                ];

                continue;
            }

            if (! $os->horario_previsto || $os->status === StatusOrdemServico::CANCELADA) {
                continue;
            }

            $previsto = SuporteOperacaoDoDia::horarioPrevistoEm($agora, $os->horario_previsto);
            $atrasado = $previsto->copy()->addMinutes($tolerancia)->lt($agora);

            $blocos[] = [
                'inicio' => $previsto,
                'fim' => $previsto->copy()->addMinutes($atrasado ? 15 : 45),
                'status' => $atrasado ? 'ATRASO_INICIO' : 'PREVISTA',
                'ponto_venda' => $pontoVenda,
                // Ainda não existe Visita pra um bloco nominal (previsto/atrasado) — nada pra
                // detalhar num duplo clique.
                'visita_id' => null,
            ];
        }

        return $blocos;
    }

    /** @return array{total: int, pdvs: int} */
    private function contagemRupturasAbertas(Empresa $empresa): array
    {
        $linha = VisitaRegistro::query()
            ->join('visitas', 'visitas.id', '=', 'visita_registros.visita_id')
            ->join('tipos_registro', 'tipos_registro.id', '=', 'visita_registros.tipo_registro_id')
            ->whereNull('visita_registros.cancelado_em')
            ->where('visita_registros.ruptura', true)
            ->whereNull('visita_registros.alerta_resolvido_em')
            ->where('tipos_registro.eh_alerta', true)
            ->where('visitas.empresa_id', $empresa->id)
            ->selectRaw('count(*) as total, count(distinct visitas.ponto_venda_id) as pdvs')
            ->first();

        return [
            'total' => (int) ($linha->total ?? 0),
            'pdvs' => (int) ($linha->pdvs ?? 0),
        ];
    }

    /** Contagem de rupturas abertas por promotor (dono da visita) — pra coluna "Rupt." da equipe. */
    private function rupturasAbertasPorPromotor(Empresa $empresa): Collection
    {
        return VisitaRegistro::query()
            ->whereNull('cancelado_em')
            ->where('ruptura', true)
            ->whereNull('alerta_resolvido_em')
            ->whereHas('tipoRegistro', fn ($q) => $q->where('eh_alerta', true))
            ->whereHas('visita', fn ($q) => $q->where('empresa_id', $empresa->id))
            ->with('visita')
            ->get()
            ->groupBy(fn (VisitaRegistro $registro) => $registro->visita->usuario_id)
            ->map(fn (Collection $registros) => $registros->count());
    }

    /**
     * Mescla alertas de VisitaRegistro (ruptura e qualquer outro tipo marcado eh_alerta, mesmo
     * mecanismo do doc 19) com os sintéticos SINAL/ATRASO calculados do status por promotor —
     * nenhum dos dois tem endpoint de resolução próprio aqui (ver doc 32 Fase 2: a ação do
     * gestor pros sintéticos é fora do sistema, "Ligar"/"Justificar").
     *
     * @param  Collection<int, array{usuario: \App\Models\Usuario, status: string, visita_aberta: mixed, ordens: Collection}>  $porPromotor
     */
    private function filaAcoes(Empresa $empresa, Collection $porPromotor, Carbon $agora): Collection
    {
        $tolerancia = SuporteOperacaoDoDia::toleranciaAtrasoMinutos($empresa);
        $janelaSinal = now()->subMinutes(Rastreamento::JANELA_ATIVO_MINUTOS);

        $deRuptura = VisitaRegistro::query()
            ->whereNull('cancelado_em')
            ->whereNull('alerta_resolvido_em')
            ->whereHas('tipoRegistro', fn ($q) => $q->where('eh_alerta', true))
            ->whereHas('visita', fn ($q) => $q->where('empresa_id', $empresa->id))
            ->with(['visita.pontoVenda', 'visita.usuario', 'tipoRegistro', 'produtoAuditoria', 'planoAcaoAtivo'])
            ->get()
            ->map(fn (VisitaRegistro $registro) => [
                'tipo' => 'ALERTA',
                'ocorrido_em' => $registro->created_at,
                'titulo' => $registro->tipoRegistro->descricao.($registro->produtoAuditoria ? ': '.$registro->produtoAuditoria->descricao : ''),
                'ponto_venda' => $registro->visita->pontoVenda
                    ? ['id' => $registro->visita->pontoVenda->uuid, 'fantasia' => $registro->visita->pontoVenda->fantasia]
                    : null,
                'usuario' => $registro->visita->usuario
                    ? ['id' => $registro->visita->usuario->uuid, 'nome' => $registro->visita->usuario->nome]
                    : null,
                // visita_id junto do registro: o front precisa dos dois pra chamar
                // POST /visitas/{visita}/registros/{registro}/resolver-alerta (mesmo endpoint já
                // usado no Painel de Atividades, doc 19).
                // tipo/produto/observacao pré-preenchem o diálogo "Abrir Plano de Ação"; o plano
                // ativo troca "Resolver" por "Ver plano" (docs/37-PLANOS-DE-ACAO.md §5).
                'registro' => [
                    'id' => $registro->uuid,
                    'visita_id' => $registro->visita->uuid,
                    'tipo' => $registro->tipoRegistro->descricao,
                    'produto' => $registro->produtoAuditoria?->descricao,
                    'observacao' => $registro->observacao,
                    'plano_acao_ativo' => $registro->planoAcaoAtivo
                        ? ['id' => $registro->planoAcaoAtivo->uuid, 'status' => $registro->planoAcaoAtivo->status]
                        : null,
                ],
            ]);

        $deAtraso = $porPromotor
            ->filter(fn ($linha) => $linha['status'] === SuporteOperacaoDoDia::STATUS_ATRASADO)
            ->map(function ($linha) use ($tolerancia, $agora) {
                $osAtrasada = $linha['ordens']
                    ->whereIn('status', [StatusOrdemServico::PENDENTE, StatusOrdemServico::EM_ANDAMENTO])
                    ->first(fn (OrdemServico $os) => $os->horario_previsto
                        && SuporteOperacaoDoDia::horarioPrevistoEm($agora, $os->horario_previsto)->addMinutes($tolerancia)->lt($agora));

                $previsto = $osAtrasada ? SuporteOperacaoDoDia::horarioPrevistoEm($agora, $osAtrasada->horario_previsto) : null;

                return [
                    'tipo' => 'ATRASO',
                    'ocorrido_em' => $previsto,
                    'titulo' => $linha['usuario']->nome.': atraso no início do roteiro',
                    'ponto_venda' => $osAtrasada?->pontoVenda
                        ? ['id' => $osAtrasada->pontoVenda->uuid, 'fantasia' => $osAtrasada->pontoVenda->fantasia]
                        : null,
                    'usuario' => ['id' => $linha['usuario']->uuid, 'nome' => $linha['usuario']->nome],
                    'registro' => null,
                ];
            });

        $deSinal = $porPromotor
            ->filter(fn ($linha) => $linha['status'] !== SuporteOperacaoDoDia::STATUS_ENCERRADO)
            ->filter(fn ($linha) => ! $linha['usuario']->ultima_localizacao_em || $linha['usuario']->ultima_localizacao_em->lt($janelaSinal))
            ->map(fn ($linha) => [
                'tipo' => 'SINAL',
                'ocorrido_em' => $linha['usuario']->ultima_localizacao_em,
                'titulo' => $linha['usuario']->nome.': sem sinal de GPS',
                'ponto_venda' => null,
                'usuario' => ['id' => $linha['usuario']->uuid, 'nome' => $linha['usuario']->nome],
                'registro' => null,
            ]);

        return $deRuptura->concat($deAtraso)->concat($deSinal)
            ->sortByDesc(fn ($item) => $item['ocorrido_em'] ?? Carbon::createFromTimestamp(0))
            ->values();
    }
}

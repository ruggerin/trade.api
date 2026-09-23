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

        $empresa = $usuario->empresa;
        $agora = now();
        $janelaSinal = now()->subMinutes(Rastreamento::JANELA_ATIVO_MINUTOS);

        $porPromotor = SuporteOperacaoDoDia::statusPorPromotor($empresa);
        $rupturasAbertas = $this->rupturasAbertasPorPromotor($empresa);

        $equipe = $porPromotor->map(function (array $linha) use ($janelaSinal, $rupturasAbertas) {
            $promotor = $linha['usuario'];
            $visita = $linha['visita_aberta'];
            $ordens = $linha['ordens'];
            $semSinal = ! $promotor->ultima_localizacao_em || $promotor->ultima_localizacao_em->lt($janelaSinal);

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
            ];
        })->sortBy(fn ($linha) => $linha['usuario']['nome'])->values();

        return response()->json([
            'jornada' => SuporteOperacaoDoDia::jornada($empresa),
            'kpis' => $this->kpis($empresa, $porPromotor, $equipe),
            'equipe' => $equipe,
            'fila_acoes' => $this->filaAcoes($empresa, $porPromotor, $agora),
            'rupturas_por_sku' => SuporteOperacaoDoDia::rupturasAbertasPorSku($empresa)->map(fn ($linha) => [
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
    private function kpis(Empresa $empresa, Collection $porPromotor, Collection $equipe): array
    {
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
            'sem_sinal' => $equipe->where('sem_sinal', true)->count(),
            'rupturas_abertas' => $this->contagemRupturasAbertas($empresa),
            'formularios_emitidos' => SuporteOperacaoDoDia::formulariosEmitidosHoje($empresa),
        ];
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
            ->with(['visita.pontoVenda', 'visita.usuario', 'tipoRegistro', 'produtoAuditoria'])
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
                'registro' => ['id' => $registro->uuid],
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

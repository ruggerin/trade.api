<?php

namespace App\Http\Controllers;

use App\Enums\AcaoIntervencaoVisita;
use App\Enums\CheckoutTipo;
use App\Enums\UserType;
use App\Enums\StatusOrdemServico;
use App\Enums\StatusVisita;
use App\Http\Requests\Visita\CancelarVisitaRequest;
use App\Http\Requests\Visita\CheckinVisitaRequest;
use App\Http\Requests\Visita\CheckoutVisitaRequest;
use App\Http\Requests\Visita\CorrigirHorariosVisitaRequest;
use App\Http\Requests\Visita\ForcarCheckoutVisitaRequest;
use App\Http\Resources\VisitaResource;
use App\Models\CampanhaAuditoria;
use App\Models\OrdemServico;
use App\Models\PontoVenda;
use App\Models\Usuario;
use App\Models\Visita;
use App\Models\VisitaIntervencao;
use App\Support\Haversine;
use App\Support\RaioCheckin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class VisitaController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $usuario = $request->user();

        $query = Visita::query()
            ->with(['pontoVenda', 'usuario', 'campanha', 'ordemServico'])
            // Contagem não inclui registros cancelados — a intenção do número é "quanto foi
            // conferido de verdade", não quanto foi tentado e desfeito depois.
            ->withCount(['registros' => fn ($q) => $q->whereNull('cancelado_em')]);

        if ($usuario->user_type === UserType::PROMOTOR) {
            // PROMOTOR só vê as próprias visitas, mesmo que passe outro usuario_uuid.
            $query->where('usuario_id', $usuario->id);
        } elseif ($request->filled('usuario_uuid')) {
            if ($request->string('usuario_uuid') === 'eu') {
                $query->where('usuario_id', $usuario->id);
            } else {
                $query->where('usuario_id', Usuario::where('uuid', $request->string('usuario_uuid'))->value('id'));
            }
        }

        $query
            ->when($request->filled('ponto_venda_uuid'), function ($query) use ($request) {
                $query->where('ponto_venda_id', PontoVenda::where('uuid', $request->string('ponto_venda_uuid'))->value('id'));
            })
            ->when($request->filled('data_inicio'), fn ($query) => $query->whereDate('inicio_data', '>=', $request->string('data_inicio')))
            ->when($request->filled('data_fim'), fn ($query) => $query->whereDate('inicio_data', '<=', $request->string('data_fim')))
            // docs/03-ADMIN-WEB.md §1 pede filtro por status na listagem — não estava
            // documentado em docs/02-API-BACKEND.md ainda, sincronizado junto com este código.
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')));

        $visitas = $query->orderByDesc('inicio_data')->paginate();

        return response()->json([
            'visitas' => VisitaResource::collection($visitas->items()),
            'meta' => [
                'current_page' => $visitas->currentPage(),
                'last_page' => $visitas->lastPage(),
                'per_page' => $visitas->perPage(),
                'total' => $visitas->total(),
            ],
        ]);
    }

    public function show(Request $request, Visita $visita): JsonResponse
    {
        $this->autorizarAcesso($request, $visita);

        $visita->load([
            'pontoVenda', 'usuario', 'campanha', 'ordemServico',
            'intervencoes.usuario',
            'registros.produtoAuditoria', 'registros.tipoRegistro',
            'registros.secao', 'registros.departamento', 'registros.marca',
        ]);
        // Evita 1 query por registro só pra montar imagem_url (ver VisitaRegistroResource) —
        // a visita pai já é conhecida aqui.
        $visita->registros->each(fn ($registro) => $registro->setRelation('visita', $visita));

        return response()->json([
            'visita' => new VisitaResource($visita),
        ]);
    }

    /**
     * Check-in. Distância calculada no servidor (nunca confia no valor do cliente) — ver
     * docs/02-API-BACKEND.md, regra de negócio 1.
     */
    public function store(CheckinVisitaRequest $request): JsonResponse
    {
        $dados = $request->validated();

        if (! empty($dados['idempotency_key'])) {
            // Reenvio do mesmo check-in (ex.: app fechou entre o servidor confirmar e o celular
            // gravar o id de volta) — devolve a visita já criada em vez de duplicar. Já filtrado
            // pela empresa do usuário autenticado (BelongsToEmpresa em Visita); ainda checa
            // ownership porque duas empresas diferentes nunca colidem aqui, mas dois promotores
            // da MESMA empresa, sim (globalScope não distingue usuário).
            $existente = Visita::where('idempotency_key', $dados['idempotency_key'])->first();
            if ($existente) {
                $this->autorizarAcesso($request, $existente);

                return response()->json([
                    'visita' => new VisitaResource($existente->load(['pontoVenda', 'campanha', 'ordemServico'])),
                ], 200);
            }
        }

        $pontoVenda = PontoVenda::where('uuid', $dados['ponto_venda_uuid'])->firstOrFail();

        $distancia = Haversine::metros(
            (float) $dados['latitude'],
            (float) $dados['longitude'],
            (float) $pontoVenda->latitude,
            (float) $pontoVenda->longitude,
        );

        $raio = RaioCheckin::metros($request->user()->empresa);

        if ($distancia > $raio) {
            return response()->json([
                'message' => sprintf('Você está a %dm do ponto de venda, check-in não permitido.', round($distancia)),
                'distancia_metros' => round($distancia, 2),
            ], 422);
        }

        // Já validada contra PDV/promotor/status em CheckinVisitaRequest::withValidator — aqui
        // só resolve o model pra vincular. Ver docs/07-ORDEM-DE-SERVICO.md.
        $ordemServico = ! empty($dados['ordem_servico_uuid'])
            ? OrdemServico::withoutGlobalScopes()->where('uuid', $dados['ordem_servico_uuid'])->first()
            : null;

        $visita = Visita::create([
            'ponto_venda_id' => $pontoVenda->id,
            'usuario_id' => $request->user()->id,
            'campanha_id' => $this->resolverCampanhaUnica(),
            'ordem_servico_id' => $ordemServico?->id,
            'idempotency_key' => $dados['idempotency_key'] ?? null,
            'status' => StatusVisita::ABERTA,
            'inicio_data' => now(),
            'inicio_latitude' => $dados['latitude'],
            'inicio_longitude' => $dados['longitude'],
            'inicio_distancia_metros' => $distancia,
        ]);

        if ($ordemServico) {
            $ordemServico->update(['status' => StatusOrdemServico::EM_ANDAMENTO, 'visita_id' => $visita->id]);
        }

        return response()->json([
            'visita' => new VisitaResource($visita->load(['pontoVenda', 'campanha', 'ordemServico'])),
        ], 201);
    }

    public function checkout(CheckoutVisitaRequest $request, Visita $visita): JsonResponse
    {
        $this->autorizarAcesso($request, $visita);

        if ($visita->status !== StatusVisita::ABERTA) {
            return response()->json([
                'message' => 'Esta visita não está aberta.',
            ], 422);
        }

        $dados = $request->validated();

        $visita->loadMissing('pontoVenda');

        $distancia = Haversine::metros(
            (float) $dados['latitude'],
            (float) $dados['longitude'],
            (float) $visita->pontoVenda->latitude,
            (float) $visita->pontoVenda->longitude,
        );

        // Distância de saída é só registrada — a regra de raio (negócio 1) vale só pro
        // check-in, não bloqueia o checkout.
        $visita->update([
            'fim_data' => now(),
            'fim_latitude' => $dados['latitude'],
            'fim_longitude' => $dados['longitude'],
            'fim_distancia_metros' => $distancia,
            'status' => StatusVisita::FINALIZADA,
        ]);

        if ($visita->ordem_servico_id) {
            OrdemServico::withoutGlobalScopes()
                ->where('id', $visita->ordem_servico_id)
                ->update(['status' => StatusOrdemServico::CONCLUIDA]);
        }

        return response()->json([
            'visita' => new VisitaResource($visita),
        ]);
    }

    /**
     * Intervenção administrativa: cancela (anula) uma visita. Ver
     * docs/15-INTERVENCAO-ADMINISTRATIVA-VISITA.md. A permissão `visitas.intervir` é checada
     * pelo middleware da rota (ADMIN sempre; GESTOR só com ela no perfil). A visita fica
     * CANCELADA (terminal), os registros são preservados mas a visita deixa de contar em
     * relatório/custo, e uma OrdemServico vinculada volta pra PENDENTE.
     */
    public function cancelar(CancelarVisitaRequest $request, Visita $visita): JsonResponse
    {
        if ($visita->status === StatusVisita::CANCELADA) {
            return response()->json(['message' => 'Esta visita já está cancelada.'], 422);
        }

        $motivo = $request->validated()['motivo'];
        $antes = $this->snapshotVisita($visita);

        DB::transaction(function () use ($request, $visita, $motivo, $antes): void {
            $visita->update(['status' => StatusVisita::CANCELADA]);

            if ($visita->ordem_servico_id) {
                OrdemServico::withoutGlobalScopes()
                    ->where('id', $visita->ordem_servico_id)
                    ->update(['status' => StatusOrdemServico::PENDENTE, 'visita_id' => null]);
            }

            $this->registrarIntervencao(
                $request,
                $visita,
                AcaoIntervencaoVisita::CANCELAMENTO,
                $motivo,
                'Visita cancelada',
                $antes,
            );
        });

        return response()->json([
            'visita' => new VisitaResource($this->recarregarComIntervencoes($visita)),
        ]);
    }

    /**
     * Intervenção administrativa: força o checkout de uma visita que o promotor deixou aberta,
     * com o horário real da saída informado pelo gestor. Sem GPS (fim_latitude/longitude/
     * distancia ficam null), `checkout_tipo = ADMIN`. Ver
     * docs/15-INTERVENCAO-ADMINISTRATIVA-VISITA.md.
     */
    public function forcarCheckout(ForcarCheckoutVisitaRequest $request, Visita $visita): JsonResponse
    {
        if ($visita->status !== StatusVisita::ABERTA) {
            return response()->json(['message' => 'Só é possível forçar o checkout de uma visita aberta.'], 422);
        }

        $dados = $request->validated();
        $fimData = Carbon::parse($dados['fim_data']);

        if ($fimData->lt($visita->inicio_data)) {
            return response()->json(['message' => 'O horário de saída não pode ser antes do horário de entrada.'], 422);
        }

        if ($fimData->isFuture()) {
            return response()->json(['message' => 'O horário de saída não pode estar no futuro.'], 422);
        }

        $motivo = $dados['motivo'];
        $antes = $this->snapshotVisita($visita);

        DB::transaction(function () use ($request, $visita, $fimData, $motivo, $antes): void {
            $visita->update([
                'status' => StatusVisita::FINALIZADA,
                'fim_data' => $fimData,
                'fim_latitude' => null,
                'fim_longitude' => null,
                'fim_distancia_metros' => null,
                'checkout_tipo' => CheckoutTipo::ADMIN,
            ]);

            if ($visita->ordem_servico_id) {
                OrdemServico::withoutGlobalScopes()
                    ->where('id', $visita->ordem_servico_id)
                    ->update(['status' => StatusOrdemServico::CONCLUIDA]);
            }

            $this->registrarIntervencao(
                $request,
                $visita,
                AcaoIntervencaoVisita::CHECKOUT_FORCADO,
                $motivo,
                sprintf('Checkout forçado — saída registrada em %s', $fimData->format('d/m/Y H:i')),
                $antes,
            );
        });

        return response()->json([
            'visita' => new VisitaResource($this->recarregarComIntervencoes($visita)),
        ]);
    }

    /**
     * Intervenção administrativa: corrige os horários de entrada e/ou saída de uma visita já
     * finalizada. Não mexe em status nem em GPS. Ver docs/15-INTERVENCAO-ADMINISTRATIVA-VISITA.md.
     */
    public function corrigirHorarios(CorrigirHorariosVisitaRequest $request, Visita $visita): JsonResponse
    {
        if ($visita->status !== StatusVisita::FINALIZADA) {
            return response()->json(['message' => 'Só é possível corrigir horários de uma visita finalizada.'], 422);
        }

        $dados = $request->validated();
        $novoInicio = isset($dados['inicio_data']) ? Carbon::parse($dados['inicio_data']) : $visita->inicio_data;
        $novoFim = isset($dados['fim_data']) ? Carbon::parse($dados['fim_data']) : $visita->fim_data;

        if ($novoFim->lt($novoInicio)) {
            return response()->json(['message' => 'O horário de saída não pode ser antes do horário de entrada.'], 422);
        }

        if ($novoInicio->isFuture() || $novoFim->isFuture()) {
            return response()->json(['message' => 'Os horários não podem estar no futuro.'], 422);
        }

        $mudancas = [];
        if (! $novoInicio->eq($visita->inicio_data)) {
            $mudancas[] = sprintf(
                'entrada de %s para %s',
                $visita->inicio_data->format('d/m/Y H:i'),
                $novoInicio->format('d/m/Y H:i'),
            );
        }
        if (! $novoFim->eq($visita->fim_data)) {
            $mudancas[] = sprintf(
                'saída de %s para %s',
                $visita->fim_data->format('d/m/Y H:i'),
                $novoFim->format('d/m/Y H:i'),
            );
        }

        if ($mudancas === []) {
            return response()->json(['message' => 'Nenhum horário diferente do atual foi informado.'], 422);
        }

        $motivo = $dados['motivo'];
        $antes = $this->snapshotVisita($visita);

        DB::transaction(function () use ($request, $visita, $novoInicio, $novoFim, $motivo, $antes, $mudancas): void {
            $visita->update(['inicio_data' => $novoInicio, 'fim_data' => $novoFim]);

            $this->registrarIntervencao(
                $request,
                $visita,
                AcaoIntervencaoVisita::CORRECAO_HORARIO,
                $motivo,
                'Horário corrigido: '.implode('; ', $mudancas),
                $antes,
            );
        });

        return response()->json([
            'visita' => new VisitaResource($this->recarregarComIntervencoes($visita)),
        ]);
    }

    /**
     * @return array{status: string, inicio_data: ?string, fim_data: ?string, checkout_tipo: ?string}
     */
    private function snapshotVisita(Visita $visita): array
    {
        return [
            'status' => $visita->status->value,
            'inicio_data' => $visita->inicio_data?->toIso8601String(),
            'fim_data' => $visita->fim_data?->toIso8601String(),
            'checkout_tipo' => $visita->checkout_tipo?->value,
        ];
    }

    /**
     * Grava a linha de auditoria. `valores_novos` é o snapshot do estado JÁ mutado em memória
     * (todo caller aplica `$visita->update(...)` antes de chamar isto).
     */
    private function registrarIntervencao(
        Request $request,
        Visita $visita,
        AcaoIntervencaoVisita $acao,
        string $motivo,
        string $descricao,
        array $antes,
    ): void {
        VisitaIntervencao::create([
            'visita_id' => $visita->id,
            'usuario_id' => $request->user()->id,
            'acao' => $acao,
            'motivo' => $motivo,
            'descricao' => $descricao,
            'valores_anteriores' => $antes,
            'valores_novos' => $this->snapshotVisita($visita),
        ]);
    }

    private function recarregarComIntervencoes(Visita $visita): Visita
    {
        return $visita->load(['pontoVenda', 'usuario', 'campanha', 'ordemServico', 'intervencoes.usuario']);
    }

    public function autorizarAcesso(Request $request, Visita $visita): void
    {
        if ($request->user()->user_type === UserType::PROMOTOR && $visita->usuario_id !== $request->user()->id) {
            abort(403, 'Você não tem acesso a esta visita.');
        }
    }

    /**
     * `visitas.campanha_id` era `pesquisa_prm_id` no sistema antigo, que assumia uma única
     * campanha ativa por vez (ver docs/01-MODELO-DE-DADOS.md §3). O algoritmo novo de
     * `disponiveis` (regra de negócio 2) já resolve produtos de VÁRIAS campanhas ativas/
     * vigentes simultaneamente — não existe mais "a" campanha da visita no caso geral. Melhor
     * esforço: grava só quando há exatamente uma campanha ativa/vigente pra empresa (sem
     * ambiguidade); com zero ou mais de uma, fica `null` — mais correto que chutar qual delas
     * "é a certa".
     */
    private function resolverCampanhaUnica(): ?int
    {
        $campanhasAtivas = CampanhaAuditoria::query()
            ->where('ativo', true)
            ->where('vigencia_inicio', '<=', now())
            ->where('vigencia_fim', '>=', now())
            ->pluck('id');

        return $campanhasAtivas->count() === 1 ? $campanhasAtivas->first() : null;
    }
}

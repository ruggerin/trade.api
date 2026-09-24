<?php

namespace App\Http\Controllers;

use App\Enums\AcaoIntervencaoVisita;
use App\Enums\CheckoutTipo;
use App\Enums\Permissao;
use App\Enums\UserType;
use App\Enums\StatusOrdemServico;
use App\Enums\StatusVisita;
use App\Http\Requests\Visita\CancelarVisitaRequest;
use App\Http\Requests\Visita\CheckinVisitaRequest;
use App\Http\Requests\Visita\CheckoutVisitaRequest;
use App\Http\Requests\Visita\CorrigirHorariosVisitaRequest;
use App\Http\Requests\Visita\ForcarCheckoutVisitaRequest;
use App\Http\Resources\VisitaResource;
use App\Models\AutorizacaoGestor;
use App\Models\CampanhaAuditoria;
use App\Models\OrdemServico;
use App\Models\PontoVenda;
use App\Models\ProdutoAuditoria;
use App\Models\Usuario;
use App\Models\Visita;
use App\Models\VisitaIntervencao;
use App\Support\CancelamentoVisita;
use App\Support\DirecionamentoParametros;
use App\Support\Haversine;
use App\Support\RaioCheckin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

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
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            // Drill-down do "Rupturas por SKU" da Operação do Dia (docs/32-PAINEL-OPERACAO-DO-DIA.md)
            // pras visitas de origem — combináveis: só ruptura=1 já filtra qualquer ruptura aberta,
            // só produto_auditoria_uuid filtra qualquer registro daquele produto (não só ruptura).
            ->when(
                $request->filled('produto_auditoria_uuid') || $request->boolean('ruptura'),
                function ($query) use ($request) {
                    $query->whereHas('registros', function ($q) use ($request) {
                        $q->whereNull('cancelado_em')
                            ->when(
                                $request->filled('produto_auditoria_uuid'),
                                fn ($q) => $q->where(
                                    'produto_auditoria_id',
                                    ProdutoAuditoria::where('uuid', $request->string('produto_auditoria_uuid'))->value('id'),
                                ),
                            )
                            ->when($request->boolean('ruptura'), fn ($q) => $q->where('ruptura', true));
                    });
                },
            );

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
            'registros' => fn ($q) => $q->comContagemComentarios($request->user()->id),
            'registros.produtoAuditoria', 'registros.tipoRegistro.campos',
            'registros.secao', 'registros.departamento', 'registros.marca',
            'registros.imagens',
        ]);
        // Evita 1 query por registro só pra montar a url de cada imagem (ver
        // VisitaRegistroResource) — a visita pai já é conhecida aqui.
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

        // Já existe uma visita ABERTA deste promotor NESTA loja? Retoma ela em vez de criar outra. Duas
        // visitas abertas na mesma loja travavam a ordem de serviço na primeira (a segunda nascia sem
        // vínculo e o formulário do Direcionamento não aparecia) e deixavam lixo aberto no servidor
        // quando o app perdia a visita local. A distância já foi validada acima — retomar não burla o raio.
        $aberta = Visita::where('usuario_id', $request->user()->id)
            ->where('ponto_venda_id', $pontoVenda->id)
            ->where('status', StatusVisita::ABERTA)
            ->orderByDesc('id')
            ->first();

        if ($aberta) {
            // Se a retomada chegou com uma OS pendente e a visita aberta ainda não tem nenhuma, vincula.
            if ($ordemServico && ! $aberta->ordem_servico_id) {
                $aberta->update(['ordem_servico_id' => $ordemServico->id]);
                $ordemServico->update(['status' => StatusOrdemServico::EM_ANDAMENTO, 'visita_id' => $aberta->id]);
            }

            return response()->json([
                'visita' => new VisitaResource($aberta->load(['pontoVenda', 'campanha', 'ordemServico'])),
                'retomada' => true,
            ], 200);
        }

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

        // Formulário de Direcionamento obrigatório ainda pendente — parametrizável por empresa
        // (avisa por padrão, bloqueia se DIRECIONAMENTO_BLOQUEIA_CHECKOUT estiver ligado). Ver
        // docs/25-DIRECIONAMENTO-ORDEM-SERVICO.md §2 decisão 5 / §8.
        if ($visita->ordem_servico_id && DirecionamentoParametros::bloqueiaCheckout($request->user()->empresa)) {
            $pendente = DB::table('ordem_servico_formularios')
                ->where('ordem_servico_id', $visita->ordem_servico_id)
                ->where('obrigatorio', true)
                ->whereNull('respondido_em')
                ->exists();

            if ($pendente) {
                return response()->json([
                    'message' => 'Existe formulário obrigatório pendente desta ordem de serviço — responda antes de finalizar a visita.',
                ], 422);
            }
        }

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
     * Autosserviço: o próprio promotor cancela (anula) a visita que ele mesmo abriu, antes de
     * finalizar — ex.: check-in por engano, loja fechada. Diferente da intervenção
     * administrativa (cancelar() abaixo): sem `motivo`, sem log em `visita_intervencoes` (essa
     * tabela é reservada pra ADMIN/GESTOR agindo sobre a visita de outra pessoa), gated por
     * `Parametro` (VISITA_CANCELAMENTO_PERMITIDO, ausente/inativo = `false`, default
     * conservador) — mesmo raciocínio de App\Support\CancelamentoRegistro. ADMIN/GESTOR não
     * passam por aqui: eles já têm o `cancelar()` de baixo, sem depender deste parâmetro.
     */
    public function cancelarPropria(Request $request, Visita $visita): JsonResponse
    {
        $this->autorizarAcesso($request, $visita);

        $usuario = $request->user();
        if ($usuario->user_type !== UserType::PROMOTOR) {
            abort(403, 'Esta ação é só para o promotor cancelar a própria visita.');
        }

        if (! CancelamentoVisita::permitidoParaPromotor($usuario->empresa)) {
            abort(403, 'Cancelamento de visita não está habilitado para promotores.');
        }

        if ($visita->status !== StatusVisita::ABERTA) {
            return response()->json(['message' => 'Só é possível cancelar uma visita em andamento.'], 422);
        }

        DB::transaction(function () use ($visita): void {
            $visita->update(['status' => StatusVisita::CANCELADA]);

            if ($visita->ordem_servico_id) {
                OrdemServico::withoutGlobalScopes()
                    ->where('id', $visita->ordem_servico_id)
                    ->update(['status' => StatusOrdemServico::PENDENTE, 'visita_id' => null]);
            }
        });

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

    /**
     * Saída de segurança pra visita travada quando o promotor NÃO pode cancelar sozinho (parâmetro
     * desligado, ou o app em estado inconsistente): um ADMIN — ou GESTOR com `visitas.intervir` —
     * autoriza no aparelho do promotor, e a visita é cancelada com a autoria DELE na trilha de
     * auditoria (`visita_intervencoes`). Dois jeitos de autorizar (§12 da doc): `codigo` (gerado no
     * admin web por `AutorizacaoGestorController`, o gestor nunca digita e-mail/senha no aparelho de
     * outra pessoa — preferido) ou, ainda por compatibilidade, `email`+`senha` dele direto. Limitado
     * a 5 tentativas por promotor a cada 15 min, pra não virar adivinhação. Ver
     * docs/15-INTERVENCAO-ADMINISTRATIVA-VISITA.md.
     */
    public function cancelarAutorizado(Request $request, Visita $visita): JsonResponse
    {
        $this->autorizarAcesso($request, $visita);
        $promotor = $request->user();
        abort_if($promotor->user_type !== UserType::PROMOTOR, 403, 'Esta ação é só para o promotor.');

        // Idempotência ANTES de gastar o código: com e-mail/senha uma nova tentativa reautentica
        // igual; com código de uso único, se checássemos isto só depois de validar a autorização,
        // um retry (resposta da 1ª chamada se perdeu, código já foi consumido) falharia à toa —
        // o app já teria êxito, só não soube.
        if ($visita->status === StatusVisita::CANCELADA) {
            return response()->json(['visita' => new VisitaResource($visita)]);
        }

        $dados = $request->validate([
            'codigo' => ['nullable', 'string', 'size:6', 'required_without_all:email,senha'],
            'email' => ['nullable', 'string', 'email', 'required_without:codigo'],
            'senha' => ['nullable', 'string', 'required_without:codigo'],
            'motivo' => ['nullable', 'string', 'max:500'],
        ]);

        $chave = 'cancelar-autorizado:'.$promotor->id;
        if (RateLimiter::tooManyAttempts($chave, 5)) {
            return response()->json([
                'message' => 'Muitas tentativas. Aguarde alguns minutos antes de tentar de novo.',
            ], 429);
        }

        if (isset($dados['codigo'])) {
            $autorizacao = AutorizacaoGestor::valido($promotor->empresa_id, $dados['codigo'])->first();
            $supervisor = $autorizacao?->usuario;
            // Gerar o código já exigiu a permissão (middleware da rota) — aqui só reconfirma que
            // a conta continua ativa no intervalo (código vive até 10 min).
            $autorizado = $autorizacao !== null && $supervisor?->ativo === true;
            $mensagemNegada = 'Autorização negada. Confira o código.';
        } else {
            $supervisor = Usuario::withoutGlobalScopes()
                ->where('email', $dados['email'])
                ->where('empresa_id', $promotor->empresa_id)
                ->where('ativo', true)
                ->whereIn('user_type', [UserType::ADMIN->value, UserType::GESTOR->value])
                ->first();

            $autorizado = $supervisor
                && Hash::check($dados['senha'], $supervisor->senha_hash)
                && ($supervisor->user_type === UserType::ADMIN || ($supervisor->perfil?->tem(Permissao::VISITAS_INTERVIR) ?? false));
            // Mensagem única de propósito: não diz se o e-mail existe nem se faltou permissão.
            $mensagemNegada = 'Autorização negada. Confira o e-mail e a senha do gestor.';
        }

        if (! $autorizado) {
            RateLimiter::hit($chave, 15 * 60);

            return response()->json(['message' => $mensagemNegada], 403);
        }

        RateLimiter::clear($chave);
        if (isset($autorizacao)) {
            $autorizacao->update(['usado_em' => now()]);
        }

        if ($visita->status !== StatusVisita::ABERTA) {
            return response()->json(['message' => 'Só é possível cancelar uma visita em andamento.'], 422);
        }

        $antes = $this->snapshotVisita($visita);

        DB::transaction(function () use ($visita, $supervisor, $promotor, $dados, $antes): void {
            $visita->update(['status' => StatusVisita::CANCELADA]);

            if ($visita->ordem_servico_id) {
                OrdemServico::withoutGlobalScopes()
                    ->where('id', $visita->ordem_servico_id)
                    ->update(['status' => StatusOrdemServico::PENDENTE, 'visita_id' => null]);
            }

            VisitaIntervencao::create([
                'visita_id' => $visita->id,
                'usuario_id' => $supervisor->id,
                'acao' => AcaoIntervencaoVisita::CANCELAMENTO,
                'motivo' => $dados['motivo'] ?? 'Cancelamento autorizado no aparelho do promotor (visita travada).',
                'descricao' => "Visita cancelada com a autorização de {$supervisor->nome} no aparelho de {$promotor->nome}",
                'valores_anteriores' => $antes,
                'valores_novos' => $this->snapshotVisita($visita),
            ]);
        });

        return response()->json(['visita' => new VisitaResource($visita)]);
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

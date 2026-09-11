<?php

namespace App\Http\Controllers;

use App\Enums\OrigemOrdemServico;
use App\Enums\StatusOrdemServico;
use App\Enums\UserType;
use App\Http\Requests\OrdemServico\ReagendarOrdemServicoRequest;
use App\Http\Requests\OrdemServico\StoreOrdemServicoPropriaRequest;
use App\Http\Requests\OrdemServico\StoreOrdemServicoRequest;
use App\Http\Requests\OrdemServico\UpdateOrdemServicoRequest;
use App\Http\Resources\OrdemServicoResource;
use App\Models\ObjetivoVisita;
use App\Models\OrdemServico;
use App\Models\PontoVenda;
use App\Models\TipoVisita;
use App\Models\Usuario;
use App\Support\AutonomiaAgenda;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrdemServicoController extends Controller
{
    private const RELACOES = ['pontoVenda', 'usuario', 'campanha', 'tipoVisita', 'objetivoVisita', 'agendaVisita', 'contrato', 'visita'];

    public function index(Request $request): JsonResponse
    {
        $usuario = $request->user();

        $query = OrdemServico::query()->with(self::RELACOES);

        if ($usuario->user_type === UserType::PROMOTOR) {
            // Promotor vê só as destinadas a ele + a fila aberta (usuario_id null) — nunca as
            // de outro colega, mesmo espírito de ownership de Visita.
            $query->where(fn ($q) => $q->where('usuario_id', $usuario->id)->orWhereNull('usuario_id'));
        }

        $query
            // Aceita um valor único (?status=PENDENTE) ou vários (?status[]=PENDENTE&status[]=CONCLUIDA)
            // — a tela Agenda do mobile busca vários de uma vez (docs/13-AGENDA-MOBILE-E-AUTONOMIA.md
            // §5), o painel de aprovação do admin busca só os três status de solicitação.
            ->when($request->filled('status'), function ($q) use ($request) {
                $status = $request->input('status');
                is_array($status) ? $q->whereIn('status', $status) : $q->where('status', $status);
            })
            // Janela de data pra tela Agenda (Hoje = só hoje; Semana = hoje + 6 dias) — compara
            // contra prazo_fim, mesmo campo já usado pra calcular "Atrasada".
            ->when($request->filled('prazo_de'), fn ($q) => $q->whereDate('prazo_fim', '>=', $request->date('prazo_de')))
            ->when($request->filled('prazo_ate'), fn ($q) => $q->whereDate('prazo_fim', '<=', $request->date('prazo_ate')))
            ->when(
                $request->filled('ponto_venda_uuid'),
                fn ($q) => $q->whereHas(
                    'pontoVenda',
                    fn ($q2) => $q2->where('pontos_venda.uuid', $request->string('ponto_venda_uuid')),
                ),
            )
            ->when($request->filled('usuario_uuid'), function ($q) use ($request, $usuario) {
                if ($request->string('usuario_uuid') === 'eu') {
                    $q->where('usuario_id', $usuario->id);
                } else {
                    $q->whereHas('usuario', fn ($q2) => $q2->where('usuarios.uuid', $request->string('usuario_uuid')));
                }
            });

        $ordensServico = $query->orderBy('prazo_fim')->paginate();

        return response()->json([
            'ordens_servico' => OrdemServicoResource::collection($ordensServico->items()),
            'meta' => [
                'current_page' => $ordensServico->currentPage(),
                'last_page' => $ordensServico->lastPage(),
                'per_page' => $ordensServico->perPage(),
                'total' => $ordensServico->total(),
            ],
        ]);
    }

    public function store(StoreOrdemServicoRequest $request): JsonResponse
    {
        $dados = $request->validated();

        $ordemServico = OrdemServico::create([
            'ponto_venda_id' => $this->resolverPontoVendaId($dados['ponto_venda_uuid']),
            'usuario_id' => ! empty($dados['usuario_uuid']) ? $this->resolverUsuarioId($dados['usuario_uuid']) : null,
            'origem' => OrigemOrdemServico::MANUAL,
            'tipo_visita_id' => ! empty($dados['tipo_visita_uuid']) ? $this->resolverTipoVisitaId($dados['tipo_visita_uuid']) : null,
            'objetivo_visita_id' => ! empty($dados['objetivo_visita_uuid']) ? $this->resolverObjetivoVisitaId($dados['objetivo_visita_uuid']) : null,
            'prioridade' => $dados['prioridade'] ?? null,
            'horario_previsto' => $dados['horario_previsto'] ?? null,
            'obrigatoria' => $dados['obrigatoria'] ?? true,
            'prazo_inicio' => $dados['prazo_inicio'],
            'prazo_fim' => $dados['prazo_fim'],
            'observacao' => $dados['observacao'] ?? null,
        ]);
        $ordemServico->load(self::RELACOES);

        return response()->json([
            'ordem_servico' => new OrdemServicoResource($ordemServico),
        ], 201);
    }

    public function update(UpdateOrdemServicoRequest $request, OrdemServico $ordemServico): JsonResponse
    {
        $dados = $request->validated();

        if (array_key_exists('ponto_venda_uuid', $dados)) {
            $dados['ponto_venda_id'] = $this->resolverPontoVendaId($dados['ponto_venda_uuid']);
            unset($dados['ponto_venda_uuid']);
        }

        if (array_key_exists('usuario_uuid', $dados)) {
            $dados['usuario_id'] = $dados['usuario_uuid'] ? $this->resolverUsuarioId($dados['usuario_uuid']) : null;
            unset($dados['usuario_uuid']);
        }

        if (array_key_exists('tipo_visita_uuid', $dados)) {
            $dados['tipo_visita_id'] = $dados['tipo_visita_uuid'] ? $this->resolverTipoVisitaId($dados['tipo_visita_uuid']) : null;
            unset($dados['tipo_visita_uuid']);
        }

        if (array_key_exists('objetivo_visita_uuid', $dados)) {
            $dados['objetivo_visita_id'] = $dados['objetivo_visita_uuid'] ? $this->resolverObjetivoVisitaId($dados['objetivo_visita_uuid']) : null;
            unset($dados['objetivo_visita_uuid']);
        }

        $ordemServico->update($dados);
        $ordemServico->load(self::RELACOES);

        return response()->json([
            'ordem_servico' => new OrdemServicoResource($ordemServico),
        ]);
    }

    /**
     * Self-service: o próprio promotor cria uma OS pra si (`+ Compromisso` no mobile) — sem
     * passar pela permissão `ordens_servico.gerenciar` (é sobre a própria agenda, não a de
     * outros). Em modo autônomo já nasce PENDENTE; se a empresa exigir aprovação, nasce
     * AGUARDANDO_APROVACAO até o gestor decidir. Ver docs/13-AGENDA-MOBILE-E-AUTONOMIA.md.
     */
    public function minhas(StoreOrdemServicoPropriaRequest $request): JsonResponse
    {
        $usuario = $request->user();
        $dados = $request->validated();

        $ordemServico = OrdemServico::create([
            'ponto_venda_id' => $this->resolverPontoVendaId($dados['ponto_venda_uuid']),
            'usuario_id' => $usuario->id,
            'origem' => OrigemOrdemServico::MANUAL,
            'tipo_visita_id' => ! empty($dados['tipo_visita_uuid']) ? $this->resolverTipoVisitaId($dados['tipo_visita_uuid']) : null,
            'objetivo_visita_id' => ! empty($dados['objetivo_visita_uuid']) ? $this->resolverObjetivoVisitaId($dados['objetivo_visita_uuid']) : null,
            'prioridade' => $dados['prioridade'] ?? null,
            'horario_previsto' => $dados['horario_previsto'] ?? null,
            // Sempre obrigatória — "sugestão não bloqueante" é uma decisão do gestor sobre o
            // time dele, não faz sentido o promotor marcar o próprio compromisso como opcional.
            'obrigatoria' => true,
            'prazo_inicio' => $dados['prazo_inicio'],
            'prazo_fim' => $dados['prazo_fim'],
            'status' => AutonomiaAgenda::requerAprovacao($usuario->empresa)
                ? StatusOrdemServico::AGUARDANDO_APROVACAO
                : StatusOrdemServico::PENDENTE,
            'observacao' => $dados['observacao'] ?? null,
        ]);
        $ordemServico->load(self::RELACOES);

        return response()->json([
            'ordem_servico' => new OrdemServicoResource($ordemServico),
        ], 201);
    }

    /**
     * Self-service: o promotor propõe um novo prazo pra uma OS própria já PENDENTE. Modo
     * autônomo aplica direto; modo aprovação guarda o proposto em colunas separadas até o
     * gestor decidir (o prazo oficial não muda enquanto isso).
     */
    public function reagendar(ReagendarOrdemServicoRequest $request, OrdemServico $ordemServico): JsonResponse
    {
        $usuario = $request->user();
        $this->autorizarPropria($usuario, $ordemServico);

        if ($ordemServico->status !== StatusOrdemServico::PENDENTE) {
            return response()->json(['message' => 'Esta ordem de serviço não está pendente.'], 422);
        }

        $dados = $request->validated();

        if (AutonomiaAgenda::requerAprovacao($usuario->empresa)) {
            $ordemServico->update([
                'status' => StatusOrdemServico::REAGENDAMENTO_SOLICITADO,
                'prazo_inicio_proposto' => $dados['prazo_inicio'],
                'prazo_fim_proposto' => $dados['prazo_fim'],
                // Uma tentativa nova não deve carregar o motivo de uma rejeição anterior.
                'motivo_rejeicao' => null,
            ]);
        } else {
            $ordemServico->update([
                'prazo_inicio' => $dados['prazo_inicio'],
                'prazo_fim' => $dados['prazo_fim'],
            ]);
        }

        $ordemServico->load(self::RELACOES);

        return response()->json(['ordem_servico' => new OrdemServicoResource($ordemServico)]);
    }

    /**
     * Self-service: o promotor cancela (ou pede cancelamento de) uma OS própria já PENDENTE.
     */
    public function cancelar(Request $request, OrdemServico $ordemServico): JsonResponse
    {
        $usuario = $request->user();
        $this->autorizarPropria($usuario, $ordemServico);

        if ($ordemServico->status !== StatusOrdemServico::PENDENTE) {
            return response()->json(['message' => 'Esta ordem de serviço não está pendente.'], 422);
        }

        $ordemServico->update([
            'status' => AutonomiaAgenda::requerAprovacao($usuario->empresa)
                ? StatusOrdemServico::CANCELAMENTO_SOLICITADO
                : StatusOrdemServico::CANCELADA,
            // Uma tentativa nova não deve carregar o motivo de uma rejeição anterior.
            'motivo_rejeicao' => null,
        ]);
        $ordemServico->load(self::RELACOES);

        return response()->json(['ordem_servico' => new OrdemServicoResource($ordemServico)]);
    }

    /**
     * Gestor aprova uma solicitação pendente (`ordens_servico.gerenciar`) — o resultado depende
     * de qual dos três status de solicitação a OS está. Ver docs/13-AGENDA-MOBILE-E-AUTONOMIA.md §4.3.
     */
    public function aprovar(OrdemServico $ordemServico): JsonResponse
    {
        match ($ordemServico->status) {
            StatusOrdemServico::AGUARDANDO_APROVACAO => $ordemServico->update([
                'status' => StatusOrdemServico::PENDENTE, 'motivo_rejeicao' => null,
            ]),
            StatusOrdemServico::REAGENDAMENTO_SOLICITADO => $ordemServico->update([
                'status' => StatusOrdemServico::PENDENTE,
                'prazo_inicio' => $ordemServico->prazo_inicio_proposto,
                'prazo_fim' => $ordemServico->prazo_fim_proposto,
                'prazo_inicio_proposto' => null,
                'prazo_fim_proposto' => null,
                'motivo_rejeicao' => null,
            ]),
            StatusOrdemServico::CANCELAMENTO_SOLICITADO => $ordemServico->update([
                'status' => StatusOrdemServico::CANCELADA, 'motivo_rejeicao' => null,
            ]),
            default => abort(422, 'Esta ordem de serviço não tem solicitação pendente.'),
        };

        $ordemServico->load(self::RELACOES);

        return response()->json(['ordem_servico' => new OrdemServicoResource($ordemServico)]);
    }

    /**
     * `motivo` é opcional — o promotor precisa saber que foi negado mesmo sem motivo nenhum, um
     * texto livre não é exigido. Ver docs/13-AGENDA-MOBILE-E-AUTONOMIA.md.
     */
    public function rejeitar(Request $request, OrdemServico $ordemServico): JsonResponse
    {
        $motivo = $request->filled('motivo') ? $request->string('motivo')->trim()->value() : null;

        match ($ordemServico->status) {
            // O compromisso existiu, foi negado, fica registrado — não é apagado.
            StatusOrdemServico::AGUARDANDO_APROVACAO => $ordemServico->update([
                'status' => StatusOrdemServico::CANCELADA, 'motivo_rejeicao' => $motivo,
            ]),
            StatusOrdemServico::REAGENDAMENTO_SOLICITADO => $ordemServico->update([
                'status' => StatusOrdemServico::PENDENTE,
                'prazo_inicio_proposto' => null,
                'prazo_fim_proposto' => null,
                'motivo_rejeicao' => $motivo,
            ]),
            StatusOrdemServico::CANCELAMENTO_SOLICITADO => $ordemServico->update([
                'status' => StatusOrdemServico::PENDENTE, 'motivo_rejeicao' => $motivo,
            ]),
            default => abort(422, 'Esta ordem de serviço não tem solicitação pendente.'),
        };

        $ordemServico->load(self::RELACOES);

        return response()->json(['ordem_servico' => new OrdemServicoResource($ordemServico)]);
    }

    private function autorizarPropria(Usuario $usuario, OrdemServico $ordemServico): void
    {
        if ($ordemServico->usuario_id !== $usuario->id) {
            abort(403, 'Você não tem acesso a esta ordem de serviço.');
        }
    }

    private function resolverPontoVendaId(string $uuid): ?int
    {
        return PontoVenda::withoutGlobalScopes()->where('uuid', $uuid)->value('id');
    }

    private function resolverUsuarioId(string $uuid): ?int
    {
        return Usuario::withoutGlobalScopes()->where('uuid', $uuid)->value('id');
    }

    private function resolverTipoVisitaId(string $uuid): ?int
    {
        return TipoVisita::withoutGlobalScopes()->where('uuid', $uuid)->value('id');
    }

    private function resolverObjetivoVisitaId(string $uuid): ?int
    {
        return ObjetivoVisita::withoutGlobalScopes()->where('uuid', $uuid)->value('id');
    }
}

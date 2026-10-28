<?php

namespace App\Http\Controllers;

use App\Enums\EscopoIntegracao;
use App\Http\Requests\Integracao\StoreIntegracaoRequest;
use App\Http\Requests\Integracao\UpdateIntegracaoRequest;
use App\Http\Resources\IntegracaoResource;
use App\Models\Integracao;
use App\Support\TokenIntegracao;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Tela Integrações do admin (docs/96 §8) — só ADMIN (rotas em user_type:ADMIN, como perfis): não é
 * delegável a GESTOR por perfil, pra ninguém dar a si mesmo um token com mais acesso do que tem.
 *
 * O token em texto puro só sai na resposta de criar e de "Gerar novo token"; depois disso, só o
 * prefixo.
 */
class IntegracaoController extends Controller
{
    public function escopos(): JsonResponse
    {
        return response()->json([
            'escopos' => array_map(fn (EscopoIntegracao $e) => [
                'valor' => $e->value,
                'recurso' => $e->recurso(),
                'acao' => $e->acao(),
                'rotulo' => $e->rotulo(),
                'descricao' => $e->descricao(),
                'disponivel' => $e->disponivel(),
            ], EscopoIntegracao::cases()),
            'validades' => TokenIntegracao::VALIDADES,
            'limite_padrao' => 120,
            'eventos' => array_map(fn (string $evento, EscopoIntegracao $escopo) => [
                'valor' => $evento,
                'rotulo' => match ($evento) {
                    'visita.finalizada' => 'Visita finalizada',
                    'visita.cancelada' => 'Visita cancelada',
                    'registro.criado' => 'Registro feito na visita',
                    'ruptura.registrada' => 'Ruptura registrada',
                    'ruptura.resolvida' => 'Ruptura resolvida',
                    default => $evento,
                },
                'escopo' => $escopo->value,
            ], array_keys(\App\Support\Webhooks::EVENTOS), \App\Support\Webhooks::EVENTOS),
        ]);
    }

    public function index(): JsonResponse
    {
        $integracoes = Integracao::query()
            ->with(['tokens', 'criadaPor:id,nome', 'revogadaPor:id,nome'])
            // Revogadas no fim: ficam na lista pra auditoria.
            ->orderByRaw('revogada_em is not null')
            ->orderBy('nome')
            ->get();

        return response()->json(['integracoes' => IntegracaoResource::collection($integracoes)]);
    }

    public function show(Integracao $integracao): JsonResponse
    {
        $hoje = $integracao->requisicoes()->where('created_at', '>=', now()->startOfDay());
        $integracao->uso_hoje = [
            'requisicoes' => (clone $hoje)->count(),
            'erros' => (clone $hoje)->where('status', '>=', 400)->count(),
        ];

        return response()->json(['integracao' => new IntegracaoResource($integracao->load(['tokens', 'criadaPor:id,nome', 'revogadaPor:id,nome']))]);
    }

    public function store(StoreIntegracaoRequest $request): JsonResponse
    {
        [$integracao, $token] = DB::transaction(function () use ($request) {
            $integracao = Integracao::create([
                ...$request->safe()->except('validade_dias'),
                'escopos' => array_values($request->validated('escopos')),
                'criada_por_id' => $request->user()->id,
            ]);

            return [$integracao, TokenIntegracao::emitir($integracao, $request->validated('validade_dias'))];
        });

        return response()->json([
            'integracao' => new IntegracaoResource($integracao->load(['tokens', 'criadaPor:id,nome'])),
            'token' => $token,
        ], 201);
    }

    public function update(UpdateIntegracaoRequest $request, Integracao $integracao): JsonResponse
    {
        $this->abortarSeRevogada($integracao);

        DB::transaction(function () use ($request, $integracao) {
            $dados = $request->validated();
            if (isset($dados['escopos'])) {
                $dados['escopos'] = array_values($dados['escopos']);
            }
            $integracao->update($dados);
            TokenIntegracao::sincronizarEscopos($integracao);
        });

        return $this->resposta($integracao);
    }

    public function pausar(Integracao $integracao): JsonResponse
    {
        $this->abortarSeRevogada($integracao);
        $integracao->update(['ativa' => false]);

        return $this->resposta($integracao);
    }

    public function reativar(Integracao $integracao): JsonResponse
    {
        $this->abortarSeRevogada($integracao);
        $integracao->update(['ativa' => true]);

        return $this->resposta($integracao);
    }

    /** "Gerar novo token": o antigo segue valendo por TOLERANCIA_ROTACAO_DIAS e depois vence sozinho. */
    public function rotacionar(Request $request, Integracao $integracao): JsonResponse
    {
        $this->abortarSeRevogada($integracao);
        $dados = $request->validate([
            'validade_dias' => ['nullable', 'integer', Rule::in(TokenIntegracao::VALIDADES)],
        ]);

        $token = DB::transaction(fn () => TokenIntegracao::rotacionar($integracao, $dados['validade_dias'] ?? null));

        return response()->json([
            'integracao' => new IntegracaoResource($integracao->load(['tokens', 'criadaPor:id,nome'])),
            'token' => $token,
            'antigo_valido_ate' => now()->addDays(TokenIntegracao::TOLERANCIA_ROTACAO_DIAS)->toIso8601String(),
        ]);
    }

    /** Imediato e sem volta: todos os tokens param na hora. A integração fica na lista pra auditoria. */
    public function revogar(Request $request, Integracao $integracao): JsonResponse
    {
        $this->abortarSeRevogada($integracao);
        $dados = $request->validate(['motivo' => ['required', 'string', 'max:500']]);

        DB::transaction(function () use ($request, $integracao, $dados) {
            $integracao->forceFill([
                'ativa' => false,
                'revogada_em' => now(),
                'revogada_por_id' => $request->user()->id,
                'revogada_motivo' => $dados['motivo'],
            ])->save();
            TokenIntegracao::revogarTodos($integracao);
        });

        return $this->resposta($integracao);
    }

    public function requisicoes(Request $request, Integracao $integracao): JsonResponse
    {
        $pagina = $integracao->requisicoes()
            ->when($request->boolean('so_erros'), fn ($q) => $q->where('status', '>=', 400))
            ->orderByDesc('id')
            ->paginate(min((int) $request->integer('per_page', 50), 200));

        return response()->json([
            'requisicoes' => collect($pagina->items())->map(fn ($r) => [
                'metodo' => $r->metodo,
                'rota' => $r->rota,
                'status' => $r->status,
                'ip' => $r->ip,
                'duracao_ms' => $r->duracao_ms,
                'registros' => $r->registros,
                'erro' => $r->erro,
                'created_at' => $r->created_at?->toIso8601String(),
            ]),
            'meta' => ['total' => $pagina->total(), 'current_page' => $pagina->currentPage(), 'last_page' => $pagina->lastPage()],
        ]);
    }

    private function abortarSeRevogada(Integracao $integracao): void
    {
        abort_if($integracao->revogada(), 409, 'Esta integração foi revogada. Crie uma nova.');
    }

    private function resposta(Integracao $integracao): JsonResponse
    {
        return response()->json(['integracao' => new IntegracaoResource($integracao->load(['tokens', 'criadaPor:id,nome', 'revogadaPor:id,nome']))]);
    }
}

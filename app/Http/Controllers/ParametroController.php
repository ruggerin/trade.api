<?php

namespace App\Http\Controllers;

use App\Http\Requests\Parametro\LoteParametrosRequest;
use App\Http\Requests\Parametro\StoreParametroRequest;
use App\Http\Requests\Parametro\UpdateParametroRequest;
use App\Http\Resources\ParametroResource;
use App\Models\Empresa;
use App\Models\Parametro;
use App\Models\ParametroHistorico;
use App\Support\ConfiguracoesEmpresa;
use App\Support\HistoricoParametros;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ParametroController extends Controller
{
    /**
     * Sem paginação de propósito: o mobile baixa a lista inteira de uma vez pra cachear
     * localmente (ver docs/02-API-BACKEND.md) — paginar obrigaria o cliente a varrer páginas
     * só pra montar o cache completo.
     */
    public function index(Request $request): JsonResponse
    {
        $parametros = Parametro::query()
            ->when($request->has('ativo'), fn ($query) => $query->where('ativo', $request->boolean('ativo')))
            // Só tem efeito prático pro SUPERADMIN — pra ele, que não pertence a empresa
            // nenhuma, BelongsToEmpresa não filtra a query, então sem isso a lista viria com o
            // parâmetro de toda empresa cliente misturado. Filtro de suporte: escolher uma
            // empresa pra ver o que ela configurou (ex.: CHECKIN_RAIO_METROS mal ajustado), ver
            // docs/02-API-BACKEND.md#multi-tenancy-e-isolamento-de-dados.
            ->when(
                $request->filled('empresa_uuid'),
                fn ($query) => $query->where('empresa_id', Empresa::where('uuid', $request->string('empresa_uuid'))->value('id')),
            )
            ->with('empresa')
            ->orderBy('chave')
            ->get();

        return response()->json([
            'parametros' => ParametroResource::collection($parametros),
        ]);
    }

    /**
     * Tela de Configurações (docs/62): o catálogo agrupado por área, com o valor efetivo da empresa
     * (o padrão quando ainda não há linha). O mobile continua usando `index`.
     */
    public function catalogo(Request $request): JsonResponse
    {
        return response()->json(['areas' => ConfiguracoesEmpresa::catalogo($request->user()->empresa)]);
    }

    /**
     * Histórico de alterações das configurações (auditoria): o que mudou, de quanto pra quanto,
     * quem e de onde. Filtros: `chave`, `usuario_uuid`; mais recente primeiro.
     */
    public function historico(Request $request): JsonResponse
    {
        $request->validate([
            'chave' => ['nullable', 'string', 'max:100'],
            'usuario_uuid' => ['nullable', 'uuid'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $pagina = ParametroHistorico::query()
            ->when($request->query('chave'), fn ($q, $chave) => $q->where('chave', $chave))
            ->when($request->query('usuario_uuid'), fn ($q, $uuid) => $q->whereHas('usuario', fn ($u) => $u->withoutGlobalScopes()->where('uuid', $uuid)))
            ->orderByDesc('id')
            ->paginate((int) $request->integer('per_page', 50));

        return response()->json([
            'historico' => collect($pagina->items())->map(fn (ParametroHistorico $h) => [
                'chave' => $h->chave,
                'rotulo' => \App\Support\ParametrosMetadados::de($h->chave)['rotulo'] ?? $h->chave,
                'valor_anterior' => $h->valor_anterior,
                'valor_novo' => $h->valor_novo,
                'ativo_anterior' => $h->ativo_anterior,
                'ativo_novo' => $h->ativo_novo,
                'por' => $h->usuario_nome,
                'origem' => $h->origem,
                'em' => $h->created_at?->toIso8601String(),
            ])->values(),
            'meta' => ['total' => $pagina->total(), 'current_page' => $pagina->currentPage(), 'last_page' => $pagina->lastPage()],
        ]);
    }

    /** Salva várias configurações de uma vez, tudo ou nada, criando a linha de quem ainda não a tem. */
    public function lote(LoteParametrosRequest $request): JsonResponse
    {
        $empresa = $request->user()->empresa;
        HistoricoParametros::comOrigem(HistoricoParametros::CONFIGURACOES, fn () => ConfiguracoesEmpresa::salvarLote($empresa, $request->validated('alteracoes')));

        return response()->json(['areas' => ConfiguracoesEmpresa::catalogo($empresa)]);
    }

    public function store(StoreParametroRequest $request): JsonResponse
    {
        $parametro = HistoricoParametros::comOrigem(HistoricoParametros::AVANCADO, fn () => Parametro::create($request->validated()));

        return response()->json([
            'parametro' => new ParametroResource($parametro),
        ], 201);
    }

    public function update(UpdateParametroRequest $request, Parametro $parametro): JsonResponse
    {
        HistoricoParametros::comOrigem(HistoricoParametros::AVANCADO, fn () => $parametro->update($request->validated()));

        return response()->json([
            'parametro' => new ParametroResource($parametro),
        ]);
    }

    public function destroy(Parametro $parametro): JsonResponse
    {
        // Soft delete (ativo = false), nunca hard delete — mesmo padrão do resto da API.
        HistoricoParametros::comOrigem(HistoricoParametros::AVANCADO, fn () => $parametro->update(['ativo' => false]));

        return response()->json(status: 204);
    }
}

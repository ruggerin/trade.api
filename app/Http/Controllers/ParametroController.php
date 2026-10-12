<?php

namespace App\Http\Controllers;

use App\Http\Requests\Parametro\LoteParametrosRequest;
use App\Http\Requests\Parametro\StoreParametroRequest;
use App\Http\Requests\Parametro\UpdateParametroRequest;
use App\Http\Resources\ParametroResource;
use App\Models\Empresa;
use App\Models\Parametro;
use App\Support\ConfiguracoesEmpresa;
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

    /** Salva várias configurações de uma vez, tudo ou nada, criando a linha de quem ainda não a tem. */
    public function lote(LoteParametrosRequest $request): JsonResponse
    {
        $empresa = $request->user()->empresa;
        ConfiguracoesEmpresa::salvarLote($empresa, $request->validated('alteracoes'));

        return response()->json(['areas' => ConfiguracoesEmpresa::catalogo($empresa)]);
    }

    public function store(StoreParametroRequest $request): JsonResponse
    {
        $parametro = Parametro::create($request->validated());

        return response()->json([
            'parametro' => new ParametroResource($parametro),
        ], 201);
    }

    public function update(UpdateParametroRequest $request, Parametro $parametro): JsonResponse
    {
        $parametro->update($request->validated());

        return response()->json([
            'parametro' => new ParametroResource($parametro),
        ]);
    }

    public function destroy(Parametro $parametro): JsonResponse
    {
        // Soft delete (ativo = false), nunca hard delete — mesmo padrão do resto da API.
        $parametro->update(['ativo' => false]);

        return response()->json(status: 204);
    }
}

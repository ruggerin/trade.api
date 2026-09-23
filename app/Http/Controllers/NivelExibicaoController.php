<?php

namespace App\Http\Controllers;

use App\Http\Requests\NivelExibicao\StoreNivelExibicaoRequest;
use App\Http\Requests\NivelExibicao\UpdateNivelExibicaoRequest;
use App\Http\Resources\NivelExibicaoResource;
use App\Models\Empresa;
use App\Models\NivelExibicao;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NivelExibicaoController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $niveis = NivelExibicao::query()
            ->when($request->has('ativo'), fn ($query) => $query->where('ativo', $request->boolean('ativo')))
            ->when($request->filled('busca'), function ($query) use ($request) {
                $termo = '%'.addcslashes($request->string('busca'), '%_\\').'%';
                $query->where('descricao', 'ilike', $termo);
            })
            // Só tem efeito prático pro SUPERADMIN — ver DepartamentoAuditoriaController::index.
            ->when(
                $request->filled('empresa_uuid'),
                fn ($query) => $query->where('empresa_id', Empresa::where('uuid', $request->string('empresa_uuid'))->value('id')),
            )
            ->with('empresa')
            ->orderBy('descricao')
            ->paginate();

        return response()->json([
            'niveis_exibicao' => NivelExibicaoResource::collection($niveis->items()),
            'meta' => [
                'current_page' => $niveis->currentPage(),
                'last_page' => $niveis->lastPage(),
                'per_page' => $niveis->perPage(),
                'total' => $niveis->total(),
            ],
        ]);
    }

    public function store(StoreNivelExibicaoRequest $request): JsonResponse
    {
        $nivel = NivelExibicao::create($request->validated());

        return response()->json([
            'nivel_exibicao' => new NivelExibicaoResource($nivel),
        ], 201);
    }

    public function update(UpdateNivelExibicaoRequest $request, NivelExibicao $nivelExibicao): JsonResponse
    {
        $nivelExibicao->update($request->validated());

        return response()->json([
            'nivel_exibicao' => new NivelExibicaoResource($nivelExibicao),
        ]);
    }

    public function destroy(NivelExibicao $nivelExibicao): JsonResponse
    {
        // Soft delete (ativo = false), nunca hard delete — mesmo padrão do resto da API.
        $nivelExibicao->update(['ativo' => false]);

        return response()->json(status: 204);
    }
}

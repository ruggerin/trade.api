<?php

namespace App\Http\Controllers;

use App\Http\Requests\RamoAtividade\StoreRamoAtividadeRequest;
use App\Http\Requests\RamoAtividade\UpdateRamoAtividadeRequest;
use App\Http\Resources\RamoAtividadeResource;
use App\Models\Empresa;
use App\Models\RamoAtividade;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RamoAtividadeController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $ramos = RamoAtividade::query()
            ->when($request->has('ativo'), fn ($query) => $query->where('ativo', $request->boolean('ativo')))
            // Só tem efeito prático pro SUPERADMIN — BelongsToEmpresa já restringe ADMIN/GESTOR à
            // própria empresa, então filtrar por outra aqui só resulta em lista vazia (inofensivo,
            // nunca vaza dado de outro tenant). Mesmo padrão de DepartamentoAuditoriaController.
            ->when(
                $request->filled('empresa_uuid'),
                fn ($query) => $query->where('empresa_id', Empresa::where('uuid', $request->string('empresa_uuid'))->value('id')),
            )
            ->with('empresa')
            ->orderBy('descricao')
            ->paginate();

        return response()->json([
            'ramos_atividade' => RamoAtividadeResource::collection($ramos->items()),
            'meta' => [
                'current_page' => $ramos->currentPage(),
                'last_page' => $ramos->lastPage(),
                'per_page' => $ramos->perPage(),
                'total' => $ramos->total(),
            ],
        ]);
    }

    public function store(StoreRamoAtividadeRequest $request): JsonResponse
    {
        $ramo = RamoAtividade::create($request->validated());

        return response()->json([
            'ramo_atividade' => new RamoAtividadeResource($ramo),
        ], 201);
    }

    public function update(UpdateRamoAtividadeRequest $request, RamoAtividade $ramoAtividade): JsonResponse
    {
        $ramoAtividade->update($request->validated());

        return response()->json([
            'ramo_atividade' => new RamoAtividadeResource($ramoAtividade),
        ]);
    }

    public function destroy(RamoAtividade $ramoAtividade): JsonResponse
    {
        // Soft delete (ativo = false), nunca hard delete — mesmo padrão do resto da API. As
        // lojas vinculadas não são afetadas (ramo_atividade_id só fica "apontando" pra um ramo
        // inativo, continua exibível/filtrável se o admin quiser).
        $ramoAtividade->update(['ativo' => false]);

        return response()->json(status: 204);
    }
}

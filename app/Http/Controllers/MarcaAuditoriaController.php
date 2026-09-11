<?php

namespace App\Http\Controllers;

use App\Http\Requests\MarcaAuditoria\StoreMarcaAuditoriaRequest;
use App\Http\Requests\MarcaAuditoria\UpdateMarcaAuditoriaRequest;
use App\Http\Resources\MarcaAuditoriaResource;
use App\Models\Empresa;
use App\Models\MarcaAuditoria;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MarcaAuditoriaController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $marcas = MarcaAuditoria::query()
            ->when($request->has('ativo'), fn ($query) => $query->where('ativo', $request->boolean('ativo')))
            // Só tem efeito prático pro SUPERADMIN — ver DepartamentoAuditoriaController::index.
            ->when(
                $request->filled('empresa_uuid'),
                fn ($query) => $query->where('empresa_id', Empresa::where('uuid', $request->string('empresa_uuid'))->value('id')),
            )
            ->with('empresa')
            ->orderBy('descricao')
            ->paginate();

        return response()->json([
            'marcas' => MarcaAuditoriaResource::collection($marcas->items()),
            'meta' => [
                'current_page' => $marcas->currentPage(),
                'last_page' => $marcas->lastPage(),
                'per_page' => $marcas->perPage(),
                'total' => $marcas->total(),
            ],
        ]);
    }

    public function store(StoreMarcaAuditoriaRequest $request): JsonResponse
    {
        $marca = MarcaAuditoria::create($request->validated());

        return response()->json([
            'marca' => new MarcaAuditoriaResource($marca),
        ], 201);
    }

    public function update(UpdateMarcaAuditoriaRequest $request, MarcaAuditoria $marcaAuditoria): JsonResponse
    {
        $marcaAuditoria->update($request->validated());

        return response()->json([
            'marca' => new MarcaAuditoriaResource($marcaAuditoria),
        ]);
    }

    public function destroy(MarcaAuditoria $marcaAuditoria): JsonResponse
    {
        // Soft delete (ativo = false), nunca hard delete — mesmo padrão de pontos_venda (ver
        // docs/02-API-BACKEND.md). Evita quebrar FK de produtos_auditoria/campanha_itens que
        // referenciam a marca.
        $marcaAuditoria->update(['ativo' => false]);

        return response()->json(status: 204);
    }
}

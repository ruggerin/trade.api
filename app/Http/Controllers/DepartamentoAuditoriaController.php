<?php

namespace App\Http\Controllers;

use App\Http\Requests\DepartamentoAuditoria\StoreDepartamentoAuditoriaRequest;
use App\Http\Requests\DepartamentoAuditoria\UpdateDepartamentoAuditoriaRequest;
use App\Http\Resources\DepartamentoAuditoriaResource;
use App\Models\DepartamentoAuditoria;
use App\Models\Empresa;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DepartamentoAuditoriaController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $departamentos = DepartamentoAuditoria::query()
            ->when($request->has('ativo'), fn ($query) => $query->where('ativo', $request->boolean('ativo')))
            // Só tem efeito prático pro SUPERADMIN — BelongsToEmpresa já restringe ADMIN/GESTOR à
            // própria empresa, então filtrar por outra aqui só resulta em lista vazia (inofensivo,
            // nunca vaza dado de outro tenant). Sem isso, SUPERADMIN via lista de todas as
            // empresas misturada, sem como distinguir — mesmo padrão de UsuarioController::index.
            ->when(
                $request->filled('empresa_uuid'),
                fn ($query) => $query->where('empresa_id', Empresa::where('uuid', $request->string('empresa_uuid'))->value('id')),
            )
            ->with('empresa')
            ->orderBy('descricao')
            ->paginate();

        return response()->json([
            'departamentos' => DepartamentoAuditoriaResource::collection($departamentos->items()),
            'meta' => [
                'current_page' => $departamentos->currentPage(),
                'last_page' => $departamentos->lastPage(),
                'per_page' => $departamentos->perPage(),
                'total' => $departamentos->total(),
            ],
        ]);
    }

    public function store(StoreDepartamentoAuditoriaRequest $request): JsonResponse
    {
        $departamento = DepartamentoAuditoria::create($request->validated());

        return response()->json([
            'departamento' => new DepartamentoAuditoriaResource($departamento),
        ], 201);
    }

    public function update(UpdateDepartamentoAuditoriaRequest $request, DepartamentoAuditoria $departamentoAuditoria): JsonResponse
    {
        $departamentoAuditoria->update($request->validated());

        return response()->json([
            'departamento' => new DepartamentoAuditoriaResource($departamentoAuditoria),
        ]);
    }

    public function destroy(DepartamentoAuditoria $departamentoAuditoria): JsonResponse
    {
        // Soft delete (ativo = false), nunca hard delete — mesmo padrão do resto da API.
        $departamentoAuditoria->update(['ativo' => false]);

        return response()->json(status: 204);
    }
}

<?php

namespace App\Http\Controllers;

use App\Http\Requests\SecaoAuditoria\StoreSecaoAuditoriaRequest;
use App\Http\Requests\SecaoAuditoria\UpdateSecaoAuditoriaRequest;
use App\Http\Resources\SecaoAuditoriaResource;
use App\Models\DepartamentoAuditoria;
use App\Models\Empresa;
use App\Models\SecaoAuditoria;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SecaoAuditoriaController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $secoes = SecaoAuditoria::query()
            ->when($request->has('ativo'), fn ($query) => $query->where('ativo', $request->boolean('ativo')))
            ->when($request->filled('departamento_uuid'), function ($query) use ($request) {
                $query->where('departamento_id', DepartamentoAuditoria::where('uuid', $request->string('departamento_uuid'))->value('id'));
            })
            // Só tem efeito prático pro SUPERADMIN — ver DepartamentoAuditoriaController::index.
            ->when(
                $request->filled('empresa_uuid'),
                fn ($query) => $query->where('empresa_id', Empresa::where('uuid', $request->string('empresa_uuid'))->value('id')),
            )
            ->with(['departamento', 'empresa'])
            ->orderBy('descricao')
            ->paginate();

        return response()->json([
            'secoes' => SecaoAuditoriaResource::collection($secoes->items()),
            'meta' => [
                'current_page' => $secoes->currentPage(),
                'last_page' => $secoes->lastPage(),
                'per_page' => $secoes->perPage(),
                'total' => $secoes->total(),
            ],
        ]);
    }

    public function store(StoreSecaoAuditoriaRequest $request): JsonResponse
    {
        $dados = $request->validated();

        $secao = SecaoAuditoria::create([
            'descricao' => $dados['descricao'],
            'departamento_id' => isset($dados['departamento_uuid'])
                ? DepartamentoAuditoria::where('uuid', $dados['departamento_uuid'])->value('id')
                : null,
        ]);
        $secao->load('departamento');

        return response()->json([
            'secao' => new SecaoAuditoriaResource($secao),
        ], 201);
    }

    public function update(UpdateSecaoAuditoriaRequest $request, SecaoAuditoria $secaoAuditoria): JsonResponse
    {
        $dados = $request->validated();

        if (array_key_exists('departamento_uuid', $dados)) {
            $dados['departamento_id'] = $dados['departamento_uuid']
                ? DepartamentoAuditoria::where('uuid', $dados['departamento_uuid'])->value('id')
                : null;
            unset($dados['departamento_uuid']);
        }

        $secaoAuditoria->update($dados);
        $secaoAuditoria->load('departamento');

        return response()->json([
            'secao' => new SecaoAuditoriaResource($secaoAuditoria),
        ]);
    }

    public function destroy(SecaoAuditoria $secaoAuditoria): JsonResponse
    {
        $secaoAuditoria->update(['ativo' => false]);

        return response()->json(status: 204);
    }
}

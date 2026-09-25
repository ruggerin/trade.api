<?php

namespace App\Http\Controllers;

use App\Http\Requests\RedeLoja\StoreRedeLojaRequest;
use App\Http\Requests\RedeLoja\UpdateRedeLojaRequest;
use App\Http\Resources\RedeLojaResource;
use App\Models\Empresa;
use App\Models\RedeLoja;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RedeLojaController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $redes = RedeLoja::query()
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
            // Opt-in pra listar mais que os 15 padrão (capado em 200) — seletores de rede (ex.:
            // Plano de Ação) precisam da lista inteira, mesmo padrão de PontoVendaController::index.
            ->paginate($request->filled('por_pagina') ? min($request->integer('por_pagina'), 200) : null);

        return response()->json([
            'redes_lojas' => RedeLojaResource::collection($redes->items()),
            'meta' => [
                'current_page' => $redes->currentPage(),
                'last_page' => $redes->lastPage(),
                'per_page' => $redes->perPage(),
                'total' => $redes->total(),
            ],
        ]);
    }

    public function store(StoreRedeLojaRequest $request): JsonResponse
    {
        $rede = RedeLoja::create($request->validated());

        return response()->json([
            'rede_loja' => new RedeLojaResource($rede),
        ], 201);
    }

    public function update(UpdateRedeLojaRequest $request, RedeLoja $redeLoja): JsonResponse
    {
        $redeLoja->update($request->validated());

        return response()->json([
            'rede_loja' => new RedeLojaResource($redeLoja),
        ]);
    }

    public function destroy(RedeLoja $redeLoja): JsonResponse
    {
        // Soft delete (ativo = false), nunca hard delete — mesmo padrão do resto da API. As
        // lojas vinculadas não são afetadas (rede_loja_id só fica "apontando" pra uma rede
        // inativa, continua exibível/filtrável se o admin quiser).
        $redeLoja->update(['ativo' => false]);

        return response()->json(status: 204);
    }
}

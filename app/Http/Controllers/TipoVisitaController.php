<?php

namespace App\Http\Controllers;

use App\Http\Requests\TipoVisita\StoreTipoVisitaRequest;
use App\Http\Requests\TipoVisita\UpdateTipoVisitaRequest;
use App\Http\Resources\TipoVisitaResource;
use App\Models\TipoVisita;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TipoVisitaController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        // Catálogo pequeno (satélite de OrdemServico), sem paginação — mesmo padrão de
        // CategoriaCentroCustoItem, alimenta um select no formulário.
        $tiposVisita = TipoVisita::query()
            ->when($request->has('ativo'), fn ($query) => $query->where('ativo', $request->boolean('ativo')))
            ->orderBy('descricao')
            ->get();

        return response()->json([
            'tipos_visita' => TipoVisitaResource::collection($tiposVisita),
        ]);
    }

    public function store(StoreTipoVisitaRequest $request): JsonResponse
    {
        $tipoVisita = TipoVisita::create($request->validated());

        return response()->json(['tipo_visita' => new TipoVisitaResource($tipoVisita)], 201);
    }

    public function update(UpdateTipoVisitaRequest $request, TipoVisita $tipoVisita): JsonResponse
    {
        $tipoVisita->update($request->validated());

        return response()->json(['tipo_visita' => new TipoVisitaResource($tipoVisita)]);
    }

    public function destroy(TipoVisita $tipoVisita): JsonResponse
    {
        // Soft delete (ativo = false) — OS já geradas com este tipo mantêm o vínculo intacto.
        $tipoVisita->update(['ativo' => false]);

        return response()->json(status: 204);
    }
}

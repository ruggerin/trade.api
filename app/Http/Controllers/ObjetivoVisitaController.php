<?php

namespace App\Http\Controllers;

use App\Http\Requests\ObjetivoVisita\StoreObjetivoVisitaRequest;
use App\Http\Requests\ObjetivoVisita\UpdateObjetivoVisitaRequest;
use App\Http\Resources\ObjetivoVisitaResource;
use App\Models\ObjetivoVisita;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ObjetivoVisitaController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        // Catálogo pequeno (satélite de OrdemServico), sem paginação — mesmo padrão de
        // TipoVisita, alimenta um select no formulário.
        $objetivosVisita = ObjetivoVisita::query()
            ->when($request->has('ativo'), fn ($query) => $query->where('ativo', $request->boolean('ativo')))
            ->orderBy('descricao')
            ->get();

        return response()->json([
            'objetivos_visita' => ObjetivoVisitaResource::collection($objetivosVisita),
        ]);
    }

    public function store(StoreObjetivoVisitaRequest $request): JsonResponse
    {
        $objetivoVisita = ObjetivoVisita::create($request->validated());

        return response()->json(['objetivo_visita' => new ObjetivoVisitaResource($objetivoVisita)], 201);
    }

    public function update(UpdateObjetivoVisitaRequest $request, ObjetivoVisita $objetivoVisita): JsonResponse
    {
        $objetivoVisita->update($request->validated());

        return response()->json(['objetivo_visita' => new ObjetivoVisitaResource($objetivoVisita)]);
    }

    public function destroy(ObjetivoVisita $objetivoVisita): JsonResponse
    {
        // Soft delete (ativo = false) — OS já geradas com este objetivo mantêm o vínculo intacto.
        $objetivoVisita->update(['ativo' => false]);

        return response()->json(status: 204);
    }
}

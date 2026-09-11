<?php

namespace App\Http\Controllers;

use App\Http\Requests\Perfil\StorePerfilRequest;
use App\Http\Requests\Perfil\UpdatePerfilRequest;
use App\Http\Resources\PerfilResource;
use App\Models\Perfil;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PerfilController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $perfis = Perfil::query()
            ->when($request->has('ativo'), fn ($query) => $query->where('ativo', $request->boolean('ativo')))
            ->orderBy('nome')
            ->get();

        return response()->json([
            'perfis' => PerfilResource::collection($perfis),
        ]);
    }

    public function store(StorePerfilRequest $request): JsonResponse
    {
        $perfil = Perfil::create($request->validated());

        return response()->json([
            'perfil' => new PerfilResource($perfil),
        ], 201);
    }

    public function update(UpdatePerfilRequest $request, Perfil $perfil): JsonResponse
    {
        $perfil->update($request->validated());

        return response()->json([
            'perfil' => new PerfilResource($perfil),
        ]);
    }

    public function destroy(Perfil $perfil): JsonResponse
    {
        // Soft delete — usuários com esse perfil ficam sem as permissões dele
        // automaticamente, já que EnsurePermissao filtra por ativo ao checar (Perfil::tem()).
        $perfil->update(['ativo' => false]);

        return response()->json(status: 204);
    }
}

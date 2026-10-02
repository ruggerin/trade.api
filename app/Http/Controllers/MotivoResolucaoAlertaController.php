<?php

namespace App\Http\Controllers;

use App\Http\Requests\MotivoResolucaoAlerta\StoreMotivoResolucaoAlertaRequest;
use App\Http\Requests\MotivoResolucaoAlerta\UpdateMotivoResolucaoAlertaRequest;
use App\Http\Resources\MotivoResolucaoAlertaResource;
use App\Models\MotivoResolucaoAlerta;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Catálogo de motivos pra fechamento rápido de alerta (docs/56) — mesmo padrão de
 * RamoAtividadeController: cadastro simples por empresa (BelongsToEmpresa cuida do isolamento),
 * sem paginação (lista curta, pensada pra caber inteira num select).
 */
class MotivoResolucaoAlertaController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $motivos = MotivoResolucaoAlerta::query()
            ->when($request->has('ativo'), fn ($query) => $query->where('ativo', $request->boolean('ativo')))
            ->orderBy('descricao')
            ->get();

        return response()->json([
            'motivos_resolucao_alerta' => MotivoResolucaoAlertaResource::collection($motivos),
        ]);
    }

    public function store(StoreMotivoResolucaoAlertaRequest $request): JsonResponse
    {
        $motivo = MotivoResolucaoAlerta::create($request->validated());

        return response()->json([
            'motivo_resolucao_alerta' => new MotivoResolucaoAlertaResource($motivo),
        ], 201);
    }

    public function update(UpdateMotivoResolucaoAlertaRequest $request, MotivoResolucaoAlerta $motivoResolucaoAlerta): JsonResponse
    {
        $motivoResolucaoAlerta->update($request->validated());

        return response()->json([
            'motivo_resolucao_alerta' => new MotivoResolucaoAlertaResource($motivoResolucaoAlerta),
        ]);
    }

    public function destroy(MotivoResolucaoAlerta $motivoResolucaoAlerta): JsonResponse
    {
        // Soft (ativo = false), nunca hard delete — mesmo padrão do resto da API. Registros já
        // resolvidos com esse motivo mantêm o vínculo (alerta_motivo_id só aponta pra um motivo
        // inativo, continua exibível no histórico).
        $motivoResolucaoAlerta->update(['ativo' => false]);

        return response()->json(status: 204);
    }
}

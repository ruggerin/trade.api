<?php

namespace App\Http\Controllers;

use App\Http\Requests\MotivoNaoExecucao\StoreMotivoNaoExecucaoRequest;
use App\Http\Requests\MotivoNaoExecucao\UpdateMotivoNaoExecucaoRequest;
use App\Http\Resources\MotivoNaoExecucaoResource;
use App\Models\MotivoNaoExecucao;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Catálogo de motivos pra cancelamento de visita não realizada (docs/59) — mesmo padrão de
 * RamoAtividadeController: cadastro simples por empresa (BelongsToEmpresa cuida do isolamento),
 * sem paginação (lista curta, pensada pra caber inteira num select).
 */
class MotivoNaoExecucaoController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $motivos = MotivoNaoExecucao::query()
            ->when($request->has('ativo'), fn ($query) => $query->where('ativo', $request->boolean('ativo')))
            ->orderBy('descricao')
            ->get();

        return response()->json([
            'motivos_nao_execucao' => MotivoNaoExecucaoResource::collection($motivos),
        ]);
    }

    public function store(StoreMotivoNaoExecucaoRequest $request): JsonResponse
    {
        $motivo = MotivoNaoExecucao::create($request->validated());

        return response()->json([
            'motivo_nao_execucao' => new MotivoNaoExecucaoResource($motivo),
        ], 201);
    }

    public function update(UpdateMotivoNaoExecucaoRequest $request, MotivoNaoExecucao $motivoNaoExecucao): JsonResponse
    {
        $motivoNaoExecucao->update($request->validated());

        return response()->json([
            'motivo_nao_execucao' => new MotivoNaoExecucaoResource($motivoNaoExecucao),
        ]);
    }

    public function destroy(MotivoNaoExecucao $motivoNaoExecucao): JsonResponse
    {
        // Soft (ativo = false), nunca hard delete — mesmo padrão do resto da API. OS já
        // canceladas com esse motivo mantêm o vínculo (motivo_cancelamento_id só aponta pra um
        // motivo inativo, continua exibível no histórico).
        $motivoNaoExecucao->update(['ativo' => false]);

        return response()->json(status: 204);
    }
}

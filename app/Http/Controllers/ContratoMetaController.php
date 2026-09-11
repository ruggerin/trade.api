<?php

namespace App\Http\Controllers;

use App\Http\Requests\ContratoMeta\StoreContratoMetaRequest;
use App\Http\Requests\ContratoMeta\UpdateContratoMetaRequest;
use App\Http\Resources\ContratoMetaResource;
use App\Models\Contrato;
use App\Models\ContratoMeta;
use App\Models\MarcaAuditoria;
use Illuminate\Http\JsonResponse;

/**
 * Sub-recurso de Contrato (rotas aninhadas, não payload embutido no PUT /contratos/{uuid}) —
 * metas são negociadas em momentos diferentes ao longo da vida do contrato, não editadas todas
 * de uma vez. Ver docs/09-CONTRATO-METAS.md §5.
 */
class ContratoMetaController extends Controller
{
    public function store(StoreContratoMetaRequest $request, Contrato $contrato): JsonResponse
    {
        $dados = $request->validated();

        $meta = ContratoMeta::create([
            'contrato_id' => $contrato->id,
            'marca_id' => isset($dados['marca_uuid'])
                ? MarcaAuditoria::withoutGlobalScopes()->where('uuid', $dados['marca_uuid'])->value('id')
                : null,
            'descricao' => $dados['descricao'] ?? null,
            'valor_investimento' => $dados['valor_investimento'],
            'meta_valor' => $dados['meta_valor'],
            'periodo_inicio' => $dados['periodo_inicio'],
            'periodo_fim' => $dados['periodo_fim'],
            'fonte_pagamento' => $dados['fonte_pagamento'],
            'percentual_industria' => $dados['percentual_industria'] ?? null,
        ]);
        $meta->load('marca');

        return response()->json([
            'meta' => new ContratoMetaResource($meta),
        ], 201);
    }

    public function update(UpdateContratoMetaRequest $request, Contrato $contrato, ContratoMeta $meta): JsonResponse
    {
        // ContratoMeta não é tenant-aware por conta própria (herda de Contrato) — confirma
        // manualmente que a meta pertence mesmo ao contrato da rota, mesmo padrão de
        // CampanhaItemController::destroy.
        abort_if($meta->contrato_id !== $contrato->id, 404);

        $dados = $request->validated();

        if (array_key_exists('marca_uuid', $dados)) {
            $dados['marca_id'] = $dados['marca_uuid']
                ? MarcaAuditoria::withoutGlobalScopes()->where('uuid', $dados['marca_uuid'])->value('id')
                : null;
            unset($dados['marca_uuid']);
        }

        // Carimba quem apurou só na primeira vez que resultado_apurado sai de null pra um
        // valor — uma correção posterior por outra pessoa não rouba o crédito de quem apurou
        // originalmente (ver docs/09-CONTRATO-METAS.md §5/§7).
        if (
            array_key_exists('resultado_apurado', $dados)
            && $dados['resultado_apurado'] !== null
            && $meta->resultado_apurado === null
        ) {
            $dados['apurado_em'] = now();
            $dados['apurado_por_usuario_id'] = $request->user()->id;
        }

        $meta->update($dados);
        $meta->load(['marca', 'apuradoPor']);

        return response()->json([
            'meta' => new ContratoMetaResource($meta),
        ]);
    }

    public function destroy(Contrato $contrato, ContratoMeta $meta): JsonResponse
    {
        abort_if($meta->contrato_id !== $contrato->id, 404);

        // Hard delete de verdade (sem coluna ativo) — é só o registro da negociação, sem
        // consequência histórica em remover se foi cadastrada errada, mesmo raciocínio de
        // CampanhaItem::destroy.
        $meta->delete();

        return response()->json(status: 204);
    }
}

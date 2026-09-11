<?php

namespace App\Http\Controllers;

use App\Http\Requests\CampanhaItem\StoreCampanhaItemRequest;
use App\Http\Resources\CampanhaItemResource;
use App\Models\CampanhaAuditoria;
use App\Models\CampanhaItem;
use App\Models\DepartamentoAuditoria;
use App\Models\MarcaAuditoria;
use App\Models\ProdutoAuditoria;
use App\Models\SecaoAuditoria;
use Illuminate\Http\JsonResponse;

class CampanhaItemController extends Controller
{
    /**
     * A parte que implementa a hierarquia customizável: um item pode cobrir um PRODUTO
     * específico, ou uma SECAO/DEPARTAMENTO/MARCA inteira de uma vez — misturados na mesma
     * campanha. Ver regra de negócio 2, docs/02-API-BACKEND.md.
     */
    public function store(StoreCampanhaItemRequest $request, CampanhaAuditoria $campanhaAuditoria): JsonResponse
    {
        $dados = $request->validated();

        $item = CampanhaItem::create([
            'campanha_id' => $campanhaAuditoria->id,
            'tipo_item' => $dados['tipo_item'],
            'produto_id' => isset($dados['produto_uuid'])
                ? ProdutoAuditoria::where('uuid', $dados['produto_uuid'])->value('id')
                : null,
            'departamento_id' => isset($dados['departamento_uuid'])
                ? DepartamentoAuditoria::where('uuid', $dados['departamento_uuid'])->value('id')
                : null,
            'secao_id' => isset($dados['secao_uuid'])
                ? SecaoAuditoria::where('uuid', $dados['secao_uuid'])->value('id')
                : null,
            'marca_id' => isset($dados['marca_uuid'])
                ? MarcaAuditoria::where('uuid', $dados['marca_uuid'])->value('id')
                : null,
        ]);
        $item->load(['produto', 'departamento', 'secao', 'marca']);

        return response()->json([
            'item' => new CampanhaItemResource($item),
        ], 201);
    }

    public function destroy(CampanhaAuditoria $campanhaAuditoria, CampanhaItem $item): JsonResponse
    {
        // CampanhaItem não é tenant-aware por conta própria (herda de CampanhaAuditoria) —
        // confirma manualmente que o item pertence mesmo à campanha da rota.
        abort_if($item->campanha_id !== $campanhaAuditoria->id, 404);

        // Hard delete de verdade (não soft) — campanha_itens não tem coluna `ativo` (decisão
        // de schema já tomada) e nada mais referencia esse id; é só a definição de cobertura
        // da campanha, sem consequência histórica em manter a linha.
        $item->delete();

        return response()->json(status: 204);
    }
}

<?php

namespace App\Http\Controllers;

use App\Http\Requests\PlanogramaBloco\StorePlanogramaBlocoRequest;
use App\Http\Requests\PlanogramaBloco\UpdatePlanogramaBlocoRequest;
use App\Http\Resources\PlanogramaBlocoResource;
use App\Models\Planograma;
use App\Models\PlanogramaBloco;
use App\Models\PlanogramaPrateleira;
use App\Models\ProdutoAuditoria;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class PlanogramaBlocoController extends Controller
{
    /**
     * Cria 1..N blocos do mesmo produto numa transação só — cobre o arrasto de 1 célula e o
     * "aplicar aos selecionados" do editor (várias células de uma vez). Ver
     * docs/22-PLANOGRAMA.md §4 e StorePlanogramaBlocoRequest (validação de sobreposição/limite).
     */
    public function store(StorePlanogramaBlocoRequest $request, Planograma $planograma, PlanogramaPrateleira $prateleira): JsonResponse
    {
        abort_if($prateleira->planograma_id !== $planograma->id, 404);

        $dados = $request->validated();
        $produtoAuditoriaId = ProdutoAuditoria::where('uuid', $dados['produto_auditoria_uuid'])->value('id');

        $blocos = DB::transaction(function () use ($prateleira, $produtoAuditoriaId, $dados) {
            return collect($dados['blocos'])->map(fn ($bloco) => PlanogramaBloco::create([
                'prateleira_id' => $prateleira->id,
                'posicao_inicio' => $bloco['posicao_inicio'],
                'largura' => $bloco['largura'],
                'produto_auditoria_id' => $produtoAuditoriaId,
            ]));
        });

        return response()->json([
            'blocos' => PlanogramaBlocoResource::collection($blocos->each->load('produtoAuditoria')),
        ], 201);
    }

    public function update(UpdatePlanogramaBlocoRequest $request, Planograma $planograma, PlanogramaPrateleira $prateleira, PlanogramaBloco $bloco): JsonResponse
    {
        abort_if($prateleira->planograma_id !== $planograma->id, 404);
        abort_if($bloco->prateleira_id !== $prateleira->id, 404);

        $dados = $request->validated();

        if (array_key_exists('produto_auditoria_uuid', $dados)) {
            $dados['produto_auditoria_id'] = ProdutoAuditoria::where('uuid', $dados['produto_auditoria_uuid'])->value('id');
            unset($dados['produto_auditoria_uuid']);
        }

        $bloco->update($dados);

        return response()->json([
            'bloco' => new PlanogramaBlocoResource($bloco->fresh()->load('produtoAuditoria')),
        ]);
    }

    public function destroy(Planograma $planograma, PlanogramaPrateleira $prateleira, PlanogramaBloco $bloco): JsonResponse
    {
        abort_if($prateleira->planograma_id !== $planograma->id, 404);
        abort_if($bloco->prateleira_id !== $prateleira->id, 404);

        $bloco->delete();

        return response()->json(status: 204);
    }
}

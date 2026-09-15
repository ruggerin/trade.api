<?php

namespace App\Http\Controllers;

use App\Http\Requests\PlanogramaPrateleira\StorePlanogramaPrateleiraRequest;
use App\Http\Requests\PlanogramaPrateleira\UpdatePlanogramaPrateleiraRequest;
use App\Http\Resources\PlanogramaPrateleiraResource;
use App\Models\Planograma;
use App\Models\PlanogramaPrateleira;
use Illuminate\Http\JsonResponse;

class PlanogramaPrateleiraController extends Controller
{
    public function store(StorePlanogramaPrateleiraRequest $request, Planograma $planograma): JsonResponse
    {
        $dados = $request->validated();

        $prateleira = PlanogramaPrateleira::create([
            'planograma_id' => $planograma->id,
            // Nova prateleira sempre vai pro fim — mesmo raciocínio de TipoRegistroController::store.
            'ordem' => ($planograma->prateleiras()->max('ordem') ?? -1) + 1,
            'descricao' => $dados['descricao'] ?? null,
            'quantidade_blocos' => $dados['quantidade_blocos'],
        ]);

        return response()->json([
            'prateleira' => new PlanogramaPrateleiraResource($prateleira),
        ], 201);
    }

    /**
     * Reduzir `quantidade_blocos` abaixo de onde algum bloco já colocado alcança pede
     * confirmação explícita (`force=true`) antes de remover esses blocos — ver
     * docs/22-PLANOGRAMA.md, decisão 2. Sem `force`, devolve 422 com a lista do que seria
     * removido, em vez de aplicar direto ou simplesmente recusar a mudança.
     */
    public function update(UpdatePlanogramaPrateleiraRequest $request, Planograma $planograma, PlanogramaPrateleira $prateleira): JsonResponse
    {
        abort_if($prateleira->planograma_id !== $planograma->id, 404);

        $dados = $request->validated();

        if (array_key_exists('quantidade_blocos', $dados)) {
            $novaQuantidade = $dados['quantidade_blocos'];
            $blocosAfetados = $prateleira->blocos()
                ->with('produtoAuditoria')
                ->get()
                ->filter(fn ($bloco) => $bloco->posicao_inicio + $bloco->largura > $novaQuantidade);

            if ($blocosAfetados->isNotEmpty() && ! ($dados['force'] ?? false)) {
                return response()->json([
                    'message' => 'Reduzir a quantidade de blocos remove produtos já posicionados. Confirme com force=true pra prosseguir.',
                    'blocos_removidos' => $blocosAfetados->map(fn ($bloco) => [
                        'id' => $bloco->uuid,
                        'posicao_inicio' => $bloco->posicao_inicio,
                        'largura' => $bloco->largura,
                        'produto_auditoria' => $bloco->produtoAuditoria ? [
                            'id' => $bloco->produtoAuditoria->uuid,
                            'descricao' => $bloco->produtoAuditoria->descricao,
                        ] : null,
                    ])->values(),
                ], 422);
            }

            if ($blocosAfetados->isNotEmpty()) {
                $prateleira->blocos()->whereIn('id', $blocosAfetados->pluck('id'))->delete();
            }
        }

        $prateleira->update(collect($dados)->except('force')->all());

        return response()->json([
            'prateleira' => new PlanogramaPrateleiraResource($prateleira->fresh()->load('blocos.produtoAuditoria')),
        ]);
    }

    public function destroy(Planograma $planograma, PlanogramaPrateleira $prateleira): JsonResponse
    {
        abort_if($prateleira->planograma_id !== $planograma->id, 404);

        // Hard delete — prateleira/bloco são estrutura do planograma, não rastro histórico (ao
        // contrário de VisitaRegistro). Blocos cascateiam via FK.
        $prateleira->delete();

        return response()->json(status: 204);
    }
}

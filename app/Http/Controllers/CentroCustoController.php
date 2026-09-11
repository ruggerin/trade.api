<?php

namespace App\Http\Controllers;

use App\Http\Requests\CentroCusto\StoreCentroCustoRequest;
use App\Http\Requests\CentroCusto\UpdateCentroCustoRequest;
use App\Http\Resources\CentroCustoResource;
use App\Models\CentroCusto;
use App\Models\CentroCustoItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CentroCustoController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $centrosCusto = CentroCusto::query()
            ->when($request->has('ativo'), fn ($query) => $query->where('ativo', $request->boolean('ativo')))
            ->with(['itens'])
            // Pré-carrega a contagem de promotores ativos numa única query (subquery), em vez de
            // CentroCusto::resumoCusto() disparar um COUNT por linha da página — ver o fallback
            // pra `promotores_count` nesse método.
            ->withCount(['promotores' => fn ($query) => $query->where('ativo', true)])
            ->orderBy('descricao')
            ->paginate();

        return response()->json([
            'centros_custo' => CentroCustoResource::collection($centrosCusto->items()),
            'meta' => [
                'current_page' => $centrosCusto->currentPage(),
                'last_page' => $centrosCusto->lastPage(),
                'per_page' => $centrosCusto->perPage(),
                'total' => $centrosCusto->total(),
            ],
        ]);
    }

    public function store(StoreCentroCustoRequest $request): JsonResponse
    {
        $dados = $request->validated();

        $centroCusto = CentroCusto::create([
            'descricao' => $dados['descricao'],
            'carga_horaria_semanal' => $dados['carga_horaria_semanal'],
        ]);
        $this->sincronizarItens($centroCusto, $dados['itens'] ?? []);
        $centroCusto->load('itens');

        return response()->json([
            'centro_custo' => new CentroCustoResource($centroCusto),
        ], 201);
    }

    public function update(UpdateCentroCustoRequest $request, CentroCusto $centroCusto): JsonResponse
    {
        $dados = $request->validated();

        $centroCusto->update(collect($dados)->except('itens')->all());

        if (array_key_exists('itens', $dados)) {
            $this->sincronizarItens($centroCusto, $dados['itens'] ?? []);
        }

        $centroCusto->load('itens');

        return response()->json([
            'centro_custo' => new CentroCustoResource($centroCusto),
        ]);
    }

    public function destroy(CentroCusto $centroCusto): JsonResponse
    {
        // Soft delete (ativo = false), nunca hard delete — promotores já vinculados continuam
        // vinculados (histórico intacto), só não pode mais ser escolhido pra um vínculo novo.
        $centroCusto->update(['ativo' => false]);

        return response()->json(status: 204);
    }

    /**
     * Substitui a lista de itens inteira (delete-all + recreate), mesmo padrão de
     * TipoRegistroController::sincronizarCampos — mais simples que reconciliar item a item, e a
     * lista costuma ser pequena.
     */
    private function sincronizarItens(CentroCusto $centroCusto, array $itens): void
    {
        CentroCustoItem::where('centro_custo_id', $centroCusto->id)->delete();

        foreach (array_values($itens) as $indice => $item) {
            CentroCustoItem::create([
                'centro_custo_id' => $centroCusto->id,
                'categoria' => $item['categoria'],
                'descricao' => $item['descricao'],
                'valor_mensal' => $item['valor_mensal'],
                'ordem' => $indice,
            ]);
        }
    }
}

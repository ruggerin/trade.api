<?php

namespace App\Http\Controllers;

use App\Enums\TipoItemCampanha;
use App\Http\Requests\CampanhaAuditoria\StoreCampanhaAuditoriaRequest;
use App\Http\Requests\CampanhaAuditoria\UpdateCampanhaAuditoriaRequest;
use App\Http\Resources\CampanhaAuditoriaResource;
use App\Models\CampanhaAuditoria;
use App\Models\MarcaDepartamento;
use App\Models\PontoVenda;
use App\Models\ProdutoAuditoria;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CampanhaAuditoriaController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $campanhas = CampanhaAuditoria::query()
            ->when($request->has('ativo'), fn ($query) => $query->where('ativo', $request->boolean('ativo')))
            ->orderByDesc('vigencia_inicio')
            ->paginate();

        return response()->json([
            'campanhas' => CampanhaAuditoriaResource::collection($campanhas->items()),
            'meta' => [
                'current_page' => $campanhas->currentPage(),
                'last_page' => $campanhas->lastPage(),
                'per_page' => $campanhas->perPage(),
                'total' => $campanhas->total(),
            ],
        ]);
    }

    public function show(CampanhaAuditoria $campanhaAuditoria): JsonResponse
    {
        $campanhaAuditoria->load(['itens.produto', 'itens.departamento', 'itens.secao', 'itens.marca']);

        return response()->json([
            'campanha' => new CampanhaAuditoriaResource($campanhaAuditoria),
        ]);
    }

    public function store(StoreCampanhaAuditoriaRequest $request): JsonResponse
    {
        $campanha = CampanhaAuditoria::create($request->validated());

        return response()->json([
            'campanha' => new CampanhaAuditoriaResource($campanha),
        ], 201);
    }

    public function update(UpdateCampanhaAuditoriaRequest $request, CampanhaAuditoria $campanhaAuditoria): JsonResponse
    {
        $campanhaAuditoria->update($request->validated());

        return response()->json([
            'campanha' => new CampanhaAuditoriaResource($campanhaAuditoria),
        ]);
    }

    public function destroy(CampanhaAuditoria $campanhaAuditoria): JsonResponse
    {
        // Soft delete (ativo = false), nunca hard delete — mesmo padrão do resto da API.
        $campanhaAuditoria->update(['ativo' => false]);

        return response()->json(status: 204);
    }

    /**
     * Resolve "o que auditar" num PDV a partir das campanhas ativas/vigentes — regra de
     * negócio 2 (docs/02-API-BACKEND.md). ponto_venda_uuid só valida que o PDV existe no
     * tenant; a resolução em si não é filtrada por PDV (mesmo comportamento do sistema antigo).
     */
    public function disponiveis(Request $request): JsonResponse
    {
        $request->validate(['ponto_venda_uuid' => ['required', 'string']]);

        PontoVenda::where('uuid', $request->string('ponto_venda_uuid'))->firstOrFail();

        $campanhas = CampanhaAuditoria::query()
            ->where('ativo', true)
            ->where('vigencia_inicio', '<=', now())
            ->where('vigencia_fim', '>=', now())
            ->with('itens')
            ->get();

        $produtos = collect();

        foreach ($campanhas as $campanha) {
            foreach ($campanha->itens as $item) {
                // `ativo=true` em todo ramo — sem isso, um produto ainda PENDENTE de aprovação
                // (self-service do promotor, ver docs/14-SORTIMENTO-PONTO-VENDA.md §8) ou já
                // desativado/rejeitado aparecia no checklist de qualquer PDV assim que caísse
                // dentro de uma seção/departamento/marca já coberta por campanha.
                $produtosResolvidos = match ($item->tipo_item) {
                    TipoItemCampanha::PRODUTO => $item->produto_id
                        ? ProdutoAuditoria::with('secao')->where('id', $item->produto_id)->where('ativo', true)->get()
                        : collect(),
                    TipoItemCampanha::SECAO => ProdutoAuditoria::with('secao')->where('secao_id', $item->secao_id)->where('ativo', true)->get(),
                    TipoItemCampanha::DEPARTAMENTO => ProdutoAuditoria::with('secao')->where('departamento_id', $item->departamento_id)->where('ativo', true)->get(),
                    TipoItemCampanha::MARCA => ProdutoAuditoria::with('secao')->whereIn(
                        'departamento_id',
                        MarcaDepartamento::where('marca_id', $item->marca_id)->pluck('departamento_id'),
                    )->where('ativo', true)->get(),
                };

                foreach ($produtosResolvidos as $produto) {
                    // Dedup por id interno — a chave do array garante isso automaticamente.
                    $produtos->put($produto->id, ['produto' => $produto, 'campanha' => $campanha]);
                }
            }
        }

        $data = $produtos->values()->map(fn ($entry) => [
            'produto_uuid' => $entry['produto']->uuid,
            'descricao' => $entry['produto']->descricao,
            'imagem_url' => $entry['produto']->imagem_url,
            'propriedade' => $entry['produto']->propriedade,
            // Ver docs/16-GRANULARIDADE-CHECKLIST-AUDITORIA.md §6 — usado em
            // confirmarFinalizacao() pra nomear produto-chave ausente, em vez de só contar.
            'produto_chave' => $entry['produto']->produto_chave,
            // Usado pra agrupar por linha/seção na grade de coleta (Fase 2, ver
            // docs/16-GRANULARIDADE-CHECKLIST-AUDITORIA.md §9) — null quando o produto não tem
            // seção cadastrada.
            'secao_uuid' => $entry['produto']->secao?->uuid,
            'secao_descricao' => $entry['produto']->secao?->descricao,
            'campanha_uuid' => $entry['campanha']->uuid,
            'campanha_descricao' => $entry['campanha']->descricao,
        ]);

        return response()->json([
            'produtos' => $data->values(),
        ]);
    }
}

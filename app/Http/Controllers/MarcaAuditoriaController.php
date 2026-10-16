<?php

namespace App\Http\Controllers;

use App\Enums\Propriedade;
use App\Enums\StatusAprovacao;
use App\Support\JuntarMarcas;
use Illuminate\Validation\Rule;
use App\Http\Requests\MarcaAuditoria\StoreMarcaAuditoriaRequest;
use App\Http\Requests\MarcaAuditoria\UpdateMarcaAuditoriaRequest;
use App\Http\Resources\MarcaAuditoriaResource;
use App\Models\Empresa;
use App\Models\MarcaAuditoria;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MarcaAuditoriaController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $marcas = MarcaAuditoria::query()
            ->when($request->has('ativo'), fn ($query) => $query->where('ativo', $request->boolean('ativo')))
            ->when($request->filled('busca'), function ($query) use ($request) {
                $termo = '%'.addcslashes($request->string('busca'), '%_\\').'%';
                $query->where('descricao', 'ilike', $termo);
            })
            ->when(
                Propriedade::tryFrom((string) $request->string('propriedade')),
                fn ($query, Propriedade $propriedade) => $query->where('propriedade', $propriedade),
            )
            // Só tem efeito prático pro SUPERADMIN — ver DepartamentoAuditoriaController::index.
            ->when(
                $request->filled('empresa_uuid'),
                fn ($query) => $query->where('empresa_id', Empresa::where('uuid', $request->string('empresa_uuid'))->value('id')),
            )
            // Fila de aprovação (docs/85 §4.3): só as cadastradas pelo promotor ainda "a aprovar".
            ->when($request->boolean('a_aprovar'), fn ($query) => $query->where('status_aprovacao', StatusAprovacao::PENDENTE->value))
            // Juntada em outra (REJEITADO) não aparece mais em lista nenhuma.
            ->where(fn ($q) => $q->whereNull('status_aprovacao')->orWhere('status_aprovacao', '!=', StatusAprovacao::REJEITADO->value))
            ->with(['empresa', 'criadoPor:id,nome'])
            ->orderBy('descricao')
            // `por_pagina` opt-in: o app baixa o catálogo inteiro pro cache offline (docs/82 — antes
            // só via a 1ª página de 15). Sem ele, a paginação de sempre do admin.
            ->paginate($request->filled('por_pagina') ? max(1, min($request->integer('por_pagina'), 1000)) : null);

        return response()->json([
            'marcas' => MarcaAuditoriaResource::collection($marcas->items()),
            'meta' => [
                'current_page' => $marcas->currentPage(),
                'last_page' => $marcas->lastPage(),
                'per_page' => $marcas->perPage(),
                'total' => $marcas->total(),
            ],
        ]);
    }

    public function store(StoreMarcaAuditoriaRequest $request): JsonResponse
    {
        $marca = MarcaAuditoria::create($request->validated());

        return response()->json([
            'marca' => new MarcaAuditoriaResource($marca),
        ], 201);
    }

    public function update(UpdateMarcaAuditoriaRequest $request, MarcaAuditoria $marcaAuditoria): JsonResponse
    {
        $marcaAuditoria->update($request->validated());

        return response()->json([
            'marca' => new MarcaAuditoriaResource($marcaAuditoria),
        ]);
    }

    /** Marca cadastrada pelo promotor (docs/85 §4.2) deixa de ser "a aprovar". */
    public function aprovar(MarcaAuditoria $marcaAuditoria): JsonResponse
    {
        abort_if($marcaAuditoria->status_aprovacao !== StatusAprovacao::PENDENTE, 422, 'Esta marca não está a aprovar.');

        $marcaAuditoria->update(['status_aprovacao' => null, 'ativo' => true]);

        return response()->json(['marca' => new MarcaAuditoriaResource($marcaAuditoria->load('criadoPor'))]);
    }

    /**
     * Junta esta marca em outra que já existe (ex.: "DOWNY" do promotor em "Downy"): registros,
     * listas e cadastros passam pra marca destino e esta fica inativa. Ver App\Support\JuntarMarcas.
     */
    public function juntar(Request $request, MarcaAuditoria $marcaAuditoria): JsonResponse
    {
        $dados = $request->validate([
            'destino_uuid' => ['required', 'uuid', Rule::exists('marcas_auditoria', 'uuid')->where('empresa_id', $request->user()->empresa_id)],
        ]);
        $destino = MarcaAuditoria::where('uuid', $dados['destino_uuid'])->firstOrFail();
        abort_if($destino->id === $marcaAuditoria->id, 422, 'Escolha outra marca pra juntar.');
        abort_if($destino->status_aprovacao === StatusAprovacao::REJEITADO, 422, 'A marca destino já foi juntada em outra.');

        JuntarMarcas::executar($marcaAuditoria, $destino);

        return response()->json(['marca' => new MarcaAuditoriaResource($destino->refresh()->load('criadoPor'))]);
    }

    public function destroy(MarcaAuditoria $marcaAuditoria): JsonResponse
    {
        // Soft delete (ativo = false), nunca hard delete — mesmo padrão de pontos_venda (ver
        // docs/02-API-BACKEND.md). Evita quebrar FK de produtos_auditoria/campanha_itens que
        // referenciam a marca.
        $marcaAuditoria->update(['ativo' => false]);

        return response()->json(status: 204);
    }
}

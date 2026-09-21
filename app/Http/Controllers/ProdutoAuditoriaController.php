<?php

namespace App\Http\Controllers;

use App\Enums\AutonomiaPromotor;
use App\Enums\StatusAprovacao;
use App\Http\Requests\ProdutoAuditoria\StoreProdutoAuditoriaPropriaRequest;
use App\Http\Requests\ProdutoAuditoria\StoreProdutoAuditoriaRequest;
use App\Http\Requests\ProdutoAuditoria\UpdateProdutoAuditoriaRequest;
use App\Http\Resources\ProdutoAuditoriaResource;
use App\Models\DepartamentoAuditoria;
use App\Models\Empresa;
use App\Models\MarcaAuditoria;
use App\Models\NivelExibicao;
use App\Models\ProdutoAuditoria;
use App\Models\SecaoAuditoria;
use App\Support\AutonomiaSortimento;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProdutoAuditoriaController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $produtos = ProdutoAuditoria::query()
            ->when($request->has('ativo'), fn ($query) => $query->where('ativo', $request->boolean('ativo')))
            // Usado pelo seletor "+ adicionar produto loja" no mobile (ver
            // docs/14-SORTIMENTO-PONTO-VENDA.md §8/§9) — busca por nome entre potencialmente
            // centenas de produtos do catálogo.
            ->when(
                $request->filled('busca'),
                function ($query) use ($request) {
                    // Nome, código de barras OU código externo na mesma caixa — ver
                    // docs/27-BUSCA-MULTIPLA-DE-PRODUTOS.md decisão 4. Agrupado pra não vazar o OR
                    // pros demais filtros.
                    $termo = '%'.addcslashes($request->string('busca'), '%_\\').'%';
                    $query->where(fn ($q) => $q
                        ->where('descricao', 'ilike', $termo)
                        ->orWhere('codigo_barras', 'ilike', $termo)
                        ->orWhere('codigo_externo', 'ilike', $termo));
                },
            )
            ->when($request->filled('departamento_uuid'), function ($query) use ($request) {
                $query->where('departamento_id', DepartamentoAuditoria::where('uuid', $request->string('departamento_uuid'))->value('id'));
            })
            ->when($request->filled('secao_uuid'), function ($query) use ($request) {
                $query->where('secao_id', SecaoAuditoria::where('uuid', $request->string('secao_uuid'))->value('id'));
            })
            ->when($request->filled('marca_uuid'), function ($query) use ($request) {
                $query->where('marca_id', MarcaAuditoria::where('uuid', $request->string('marca_uuid'))->value('id'));
            })
            // Só tem efeito prático pro SUPERADMIN — ver DepartamentoAuditoriaController::index.
            ->when(
                $request->filled('empresa_uuid'),
                fn ($query) => $query->where('empresa_id', Empresa::where('uuid', $request->string('empresa_uuid'))->value('id')),
            )
            ->with(['departamento', 'secao', 'marca', 'nivelExibicao', 'empresa'])
            ->orderBy('descricao')
            ->paginate();

        return response()->json([
            'produtos' => ProdutoAuditoriaResource::collection($produtos->items()),
            'meta' => [
                'current_page' => $produtos->currentPage(),
                'last_page' => $produtos->lastPage(),
                'per_page' => $produtos->perPage(),
                'total' => $produtos->total(),
            ],
        ]);
    }

    public function store(StoreProdutoAuditoriaRequest $request): JsonResponse
    {
        $dados = $request->validated();

        $produto = ProdutoAuditoria::create([
            ...$dados,
            'departamento_id' => isset($dados['departamento_uuid'])
                ? DepartamentoAuditoria::where('uuid', $dados['departamento_uuid'])->value('id')
                : null,
            'secao_id' => isset($dados['secao_uuid'])
                ? SecaoAuditoria::where('uuid', $dados['secao_uuid'])->value('id')
                : null,
            'marca_id' => isset($dados['marca_uuid'])
                ? MarcaAuditoria::where('uuid', $dados['marca_uuid'])->value('id')
                : null,
            'nivel_exibicao_id' => isset($dados['nivel_exibicao_uuid'])
                ? NivelExibicao::where('uuid', $dados['nivel_exibicao_uuid'])->value('id')
                : null,
        ]);
        $produto->load(['departamento', 'secao', 'marca', 'nivelExibicao']);

        return response()->json([
            'produto' => new ProdutoAuditoriaResource($produto),
        ], 201);
    }

    public function update(UpdateProdutoAuditoriaRequest $request, ProdutoAuditoria $produtoAuditoria): JsonResponse
    {
        $dados = $request->validated();

        if (array_key_exists('departamento_uuid', $dados)) {
            $dados['departamento_id'] = $dados['departamento_uuid']
                ? DepartamentoAuditoria::where('uuid', $dados['departamento_uuid'])->value('id')
                : null;
            unset($dados['departamento_uuid']);
        }

        if (array_key_exists('secao_uuid', $dados)) {
            $dados['secao_id'] = $dados['secao_uuid']
                ? SecaoAuditoria::where('uuid', $dados['secao_uuid'])->value('id')
                : null;
            unset($dados['secao_uuid']);
        }

        if (array_key_exists('marca_uuid', $dados)) {
            $dados['marca_id'] = $dados['marca_uuid']
                ? MarcaAuditoria::where('uuid', $dados['marca_uuid'])->value('id')
                : null;
            unset($dados['marca_uuid']);
        }

        if (array_key_exists('nivel_exibicao_uuid', $dados)) {
            $dados['nivel_exibicao_id'] = $dados['nivel_exibicao_uuid']
                ? NivelExibicao::where('uuid', $dados['nivel_exibicao_uuid'])->value('id')
                : null;
            unset($dados['nivel_exibicao_uuid']);
        }

        $produtoAuditoria->update($dados);
        $produtoAuditoria->load(['departamento', 'secao', 'marca', 'nivelExibicao']);

        return response()->json([
            'produto' => new ProdutoAuditoriaResource($produtoAuditoria),
        ]);
    }

    public function destroy(ProdutoAuditoria $produtoAuditoria): JsonResponse
    {
        $produtoAuditoria->update(['ativo' => false]);

        return response()->json(status: 204);
    }

    /**
     * Self-service (mobile): o promotor cadastra um produto que ainda não existe no catálogo,
     * durante a visita ("ele mesmo cadastrar") — sem passar pela permissão `catalogo.gerenciar`.
     * Nasce ativo (modo `AUTONOMO`) ou `PENDENTE`/inativo até um gestor decidir (modo
     * `REQUER_APROVACAO`, default); some do app se `DESABILITADO`. Ver
     * docs/14-SORTIMENTO-PONTO-VENDA.md §9.
     */
    public function proprio(StoreProdutoAuditoriaPropriaRequest $request): JsonResponse
    {
        $usuario = $request->user();
        $autonomia = AutonomiaSortimento::paraCadastro($usuario->empresa);

        if ($autonomia === AutonomiaPromotor::DESABILITADO) {
            abort(403, 'Cadastro de produto não está habilitado para promotores.');
        }

        $dados = $request->validated();

        $produto = ProdutoAuditoria::create([
            ...$dados,
            'departamento_id' => isset($dados['departamento_uuid'])
                ? DepartamentoAuditoria::where('uuid', $dados['departamento_uuid'])->value('id')
                : null,
            'secao_id' => isset($dados['secao_uuid'])
                ? SecaoAuditoria::where('uuid', $dados['secao_uuid'])->value('id')
                : null,
            'criado_por_usuario_id' => $usuario->id,
            'ativo' => $autonomia === AutonomiaPromotor::AUTONOMO,
            'status_aprovacao' => $autonomia === AutonomiaPromotor::REQUER_APROVACAO ? StatusAprovacao::PENDENTE : null,
        ]);
        $produto->load(['departamento', 'secao', 'criadoPor']);

        return response()->json([
            'produto' => new ProdutoAuditoriaResource($produto),
        ], 201);
    }

    /**
     * Gestor aprova um produto `PENDENTE` (`catalogo.gerenciar`) — vira ativo, mesmo padrão dos
     * outros painéis de aprovação deste documento.
     */
    public function aprovar(ProdutoAuditoria $produtoAuditoria): JsonResponse
    {
        abort_if($produtoAuditoria->status_aprovacao !== StatusAprovacao::PENDENTE, 422, 'Este produto não está pendente.');

        $produtoAuditoria->update(['status_aprovacao' => null, 'ativo' => true]);
        $produtoAuditoria->load(['departamento', 'secao', 'criadoPor']);

        return response()->json([
            'produto' => new ProdutoAuditoriaResource($produtoAuditoria),
        ]);
    }

    /**
     * Gestor rejeita um produto `PENDENTE` — diferente do sortimento, NÃO apaga a linha (o
     * registro do promotor pode já apontar pro produto_auditoria_id); mantém inativo pra sempre.
     * Ver docs/14-SORTIMENTO-PONTO-VENDA.md §9.2.
     */
    public function rejeitar(ProdutoAuditoria $produtoAuditoria): JsonResponse
    {
        abort_if($produtoAuditoria->status_aprovacao !== StatusAprovacao::PENDENTE, 422, 'Este produto não está pendente.');

        $produtoAuditoria->update(['status_aprovacao' => StatusAprovacao::REJEITADO, 'ativo' => false]);
        $produtoAuditoria->load(['departamento', 'secao', 'criadoPor']);

        return response()->json([
            'produto' => new ProdutoAuditoriaResource($produtoAuditoria),
        ]);
    }
}

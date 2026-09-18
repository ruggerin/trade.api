<?php

namespace App\Http\Controllers;

use App\Enums\UserType;
use App\Http\Requests\PontoVenda\AtualizarFachadaPontoVendaRequest;
use App\Http\Requests\PontoVenda\StorePontoVendaRequest;
use App\Http\Requests\PontoVenda\SyncPromotoresRequest;
use App\Http\Requests\PontoVenda\UpdatePontoVendaRequest;
use App\Http\Resources\PontoVendaResource;
use App\Models\Empresa;
use App\Models\PontoVenda;
use App\Models\RamoAtividade;
use App\Models\RedeLoja;
use App\Models\Usuario;
use App\Support\VisibilidadePontosVenda;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PontoVendaController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $usuario = $request->user();

        $pontosVenda = PontoVenda::query()
            ->when($request->has('ativo'), fn ($query) => $query->where('ativo', $request->boolean('ativo')))
            ->when($request->filled('busca'), function ($query) use ($request) {
                $busca = '%'.$request->string('busca').'%';
                $query->where(function ($query) use ($busca) {
                    $query->where('razao_social', 'ilike', $busca)
                        ->orWhere('fantasia', 'ilike', $busca)
                        ->orWhere('bairro', 'ilike', $busca);
                });
            })
            // Filtros refinados pra além do "busca" genérico acima — usados pelo modal de busca
            // avançada do admin web (selecionar PDV certo entre vários parecidos), ver
            // docs/03-ADMIN-WEB.md#6-usuários. Cada um é independente, dá pra combinar.
            ->when(
                $request->filled('razao_social'),
                fn ($query) => $query->where('razao_social', 'ilike', '%'.$request->string('razao_social').'%'),
            )
            ->when(
                $request->filled('fantasia'),
                fn ($query) => $query->where('fantasia', 'ilike', '%'.$request->string('fantasia').'%'),
            )
            ->when(
                $request->filled('cnpj'),
                fn ($query) => $query->where('cnpj', 'ilike', '%'.$request->string('cnpj').'%'),
            )
            // Só tem efeito prático pro SUPERADMIN — BelongsToEmpresa não filtra a query pra
            // ele (empresa_id null), então sem isso ele veria PDVs de todas as empresas
            // misturados, sem como distinguir nem escolher o certo (ex.: ao cadastrar um
            // contrato pra uma empresa específica). Mesmo padrão de UsuarioController::index.
            ->when(
                $request->filled('empresa_uuid'),
                fn ($query) => $query->where('empresa_id', Empresa::where('uuid', $request->string('empresa_uuid'))->value('id')),
            )
            // Filtro pro admin web (ADMIN/GESTOR enxergando a carteira de um promotor
            // específico) — ver docs/02-API-BACKEND.md, regra de negócio 6.
            ->when(
                $request->filled('promotor_uuid'),
                fn ($query) => $query->whereHas(
                    'promotores',
                    fn ($q) => $q->where('usuarios.uuid', $request->string('promotor_uuid')),
                ),
            )
            // Visibilidade de PDV pro PROMOTOR — ver docs/12-VISIBILIDADE-PONTOS-DE-VENDA.md.
            // ADMIN/GESTOR/SUPERADMIN sempre veem tudo, sem nenhuma restrição abaixo.
            ->when(
                $usuario?->user_type === UserType::PROMOTOR,
                fn ($query) => VisibilidadePontosVenda::aplicarEscopoPromotor($query, $usuario),
            )
            ->with(['promotores', 'empresa', 'redeLoja', 'ramoAtividade'])
            // Ver App\Enums\EscopoAcaoTipoRegistro::CONTRATO — a Ação "exige contrato ativo"
            // precisa saber, pro app mobile, se este PDV tem algum comodato/ponto extra vigente.
            ->withExists(['contratos as tem_contrato_ativo' => fn ($query) => $query->where('ativo', true)])
            // Contagem rápida do mix — usada pelo Planejador de Visitas (mapa/relatório de
            // impressão), sem custo relevante (é só um COUNT por PDV na mesma query).
            ->withCount('sortimento')
            ->orderBy('fantasia')
            // `por_pagina` é opt-in (ninguém manda por padrão) — usado pelo Planejador de Visitas
            // pra listar a carteira inteira de um promotor de uma vez, sem paginação real.
            ->paginate($request->filled('por_pagina') ? min($request->integer('por_pagina'), 200) : null);

        return response()->json([
            'pontos_venda' => PontoVendaResource::collection($pontosVenda->items()),
            'meta' => [
                'current_page' => $pontosVenda->currentPage(),
                'last_page' => $pontosVenda->lastPage(),
                'per_page' => $pontosVenda->perPage(),
                'total' => $pontosVenda->total(),
            ],
        ]);
    }

    public function show(PontoVenda $pontoVenda): JsonResponse
    {
        // Sortimento carregado só no detalhe, não na listagem (evita inflar a resposta da lista
        // de PDVs) — ver docs/14-SORTIMENTO-PONTO-VENDA.md §4.
        $pontoVenda->load(['promotores', 'redeLoja', 'ramoAtividade', 'sortimento.produto.secao', 'sortimento.produto.departamento', 'sortimento.departamento', 'sortimento.secao', 'sortimento.marca', 'sortimento.usuario']);
        $pontoVenda->loadExists(['contratos as tem_contrato_ativo' => fn ($query) => $query->where('ativo', true)]);

        return response()->json([
            'ponto_venda' => new PontoVendaResource($pontoVenda),
        ]);
    }

    public function store(StorePontoVendaRequest $request): JsonResponse
    {
        $empresa = $request->user()->empresa;

        // Regra de negócio 4 (docs/02-API-BACKEND.md): limite de pontos de venda do plano.
        if ($empresa->limite_pontos_venda !== null && PontoVenda::count() >= $empresa->limite_pontos_venda) {
            return response()->json([
                'message' => "Limite de pontos de venda do plano {$empresa->plano->value} atingido ({$empresa->limite_pontos_venda}). Faça upgrade para adicionar mais.",
            ], 422);
        }

        $pontoVenda = PontoVenda::create($this->resolverUuidsParaIds($request->validated()));
        $pontoVenda->load(['promotores', 'redeLoja', 'ramoAtividade']); // recém-criado, mas mantém a resposta consistente com os outros endpoints

        return response()->json([
            'ponto_venda' => new PontoVendaResource($pontoVenda),
        ], 201);
    }

    public function update(UpdatePontoVendaRequest $request, PontoVenda $pontoVenda): JsonResponse
    {
        $pontoVenda->update($this->resolverUuidsParaIds($request->validated()));
        $pontoVenda->load(['promotores', 'redeLoja', 'ramoAtividade']);

        return response()->json([
            'ponto_venda' => new PontoVendaResource($pontoVenda),
        ]);
    }

    /**
     * Troca rede_loja_uuid/ramo_atividade_uuid (o que o form envia, ver Store/UpdatePontoVendaRequest)
     * pelos _id de verdade (o que o Model espera) — mesmo padrão de UsuarioController::store/update
     * pra perfil_uuid/centro_custo_uuid. `array_key_exists` (não `isset`) porque um PUT pode mandar
     * a chave com valor null de propósito, pra desvincular a rede/ramo atual.
     */
    private function resolverUuidsParaIds(array $dados): array
    {
        if (array_key_exists('rede_loja_uuid', $dados)) {
            $dados['rede_loja_id'] = $dados['rede_loja_uuid']
                ? RedeLoja::where('uuid', $dados['rede_loja_uuid'])->value('id')
                : null;
            unset($dados['rede_loja_uuid']);
        }

        if (array_key_exists('ramo_atividade_uuid', $dados)) {
            $dados['ramo_atividade_id'] = $dados['ramo_atividade_uuid']
                ? RamoAtividade::where('uuid', $dados['ramo_atividade_uuid'])->value('id')
                : null;
            unset($dados['ramo_atividade_uuid']);
        }

        return $dados;
    }

    public function fachada(PontoVenda $pontoVenda): StreamedResponse
    {
        abort_if(! $pontoVenda->fachada_path, 404);

        return Storage::disk(config('filesystems.default'))->response($pontoVenda->fachada_path);
    }

    /**
     * Substitui o arquivo anterior em vez de acumular — mesmo raciocínio de
     * AuthController::atualizarFoto (não deixa lixo órfão no disco).
     */
    public function atualizarFachada(AtualizarFachadaPontoVendaRequest $request, PontoVenda $pontoVenda): JsonResponse
    {
        if ($pontoVenda->fachada_path) {
            Storage::disk(config('filesystems.default'))->delete($pontoVenda->fachada_path);
        }

        $caminho = $request->file('imagem')->store("pontos-venda/{$pontoVenda->id}", config('filesystems.default'));
        $pontoVenda->update(['fachada_path' => $caminho]);

        return response()->json([
            'ponto_venda' => new PontoVendaResource($pontoVenda),
        ]);
    }

    public function removerFachada(PontoVenda $pontoVenda): JsonResponse
    {
        if ($pontoVenda->fachada_path) {
            Storage::disk(config('filesystems.default'))->delete($pontoVenda->fachada_path);
            $pontoVenda->update(['fachada_path' => null]);
        }

        return response()->json([
            'ponto_venda' => new PontoVendaResource($pontoVenda),
        ]);
    }

    public function destroy(PontoVenda $pontoVenda): JsonResponse
    {
        // Soft delete (ativo = false), nunca hard delete — mesmo padrão do resto da API.
        $pontoVenda->update(['ativo' => false]);

        return response()->json(status: 204);
    }

    /**
     * Sincroniza (substitui) o conjunto de promotores que atendem esta loja — ver
     * docs/02-API-BACKEND.md, regra de negócio 6. Recebe a lista completa de uuids desejada;
     * quem não estiver nela é desvinculado, mesma semântica de BelongsToMany::sync().
     */
    public function syncPromotores(SyncPromotoresRequest $request, PontoVenda $pontoVenda): JsonResponse
    {
        $usuarioIds = Usuario::withoutGlobalScopes()
            ->whereIn('uuid', $request->validated()['usuarios_uuids'])
            ->pluck('id');

        $pontoVenda->promotores()->sync($usuarioIds);
        $pontoVenda->load('promotores');

        return response()->json([
            'ponto_venda' => new PontoVendaResource($pontoVenda),
        ]);
    }

    /**
     * Atribui um único promotor à loja, sem mexer nos demais já atribuídos — diferente de
     * syncPromotores (substitui a lista inteira), este endpoint existe justamente pra evitar a
     * race condition de "ler a lista atual no cliente, somar um, mandar de volta": dois cliques
     * rápidos (ou dois admins) cada um recalculando a partir de um snapshot desatualizado podiam
     * perder a atribuição um do outro. Ver auditoria de 2026-09-10.
     */
    public function attachPromotor(PontoVenda $pontoVenda, Usuario $usuario): JsonResponse
    {
        abort_if(
            $usuario->user_type !== UserType::PROMOTOR || ! $usuario->ativo,
            422,
            'Usuário precisa ser um promotor ativo da mesma empresa.',
        );

        $pontoVenda->promotores()->syncWithoutDetaching([$usuario->id]);
        $pontoVenda->load('promotores');

        return response()->json([
            'ponto_venda' => new PontoVendaResource($pontoVenda),
        ]);
    }

    /**
     * Remove um único promotor da loja, sem mexer nos demais — ver attachPromotor.
     */
    public function detachPromotor(PontoVenda $pontoVenda, Usuario $usuario): JsonResponse
    {
        $pontoVenda->promotores()->detach($usuario->id);
        $pontoVenda->load('promotores');

        return response()->json([
            'ponto_venda' => new PontoVendaResource($pontoVenda),
        ]);
    }
}

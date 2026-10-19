<?php

namespace App\Http\Controllers;

use App\Enums\AutonomiaPromotor;
use App\Enums\Propriedade;
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
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

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
            // Própria (do cliente) vs. concorrente — ver App\Enums\Propriedade. `tryFrom` ignora
            // silenciosamente um valor inválido em vez de 422 — é um filtro de lista, não um
            // formulário de escrita.
            ->when(
                Propriedade::tryFrom((string) $request->string('propriedade')),
                fn ($query, Propriedade $propriedade) => $query->where('propriedade', $propriedade),
            )
            // Só tem efeito prático pro SUPERADMIN — ver DepartamentoAuditoriaController::index.
            ->when(
                $request->filled('empresa_uuid'),
                fn ($query) => $query->where('empresa_id', Empresa::where('uuid', $request->string('empresa_uuid'))->value('id')),
            )
            // "Colar lista de códigos" do seletor de produtos do formulário — vários códigos de
            // uma vez (código externo OU de barras, casamento exato). Aceita array ou texto
            // separado por vírgula/quebra de linha.
            ->when($request->filled('codigos'), function ($query) use ($request) {
                $codigos = $request->input('codigos');
                $lista = collect(is_array($codigos) ? $codigos : preg_split('/[\s,;]+/', (string) $codigos))
                    ->map(fn ($c) => trim((string) $c))
                    ->filter()
                    ->unique()
                    ->take(1000)
                    ->values()
                    ->all();
                $query->where(fn ($q) => $q->whereIn('codigo_externo', $lista)->orWhereIn('codigo_barras', $lista));
            })
            ->with(['departamento', 'secao', 'marca', 'nivelExibicao', 'empresa'])
            ->orderBy('descricao')
            // `por_pagina` opt-in (até 1000) — a resolução de uma lista colada precisa de tudo numa
            // resposta só; o padrão de 15 continua valendo pro resto.
            ->paginate($request->filled('por_pagina') ? min(max($request->integer('por_pagina'), 1), 1000) : null);

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

        $imagemAnterior = $produtoAuditoria->imagem_url;
        $produtoAuditoria->update($dados);
        // Trocou a foto enviada por uma URL externa (ou limpou o campo) — o arquivo antigo fica
        // órfão no storage, então sai junto.
        if ($imagemAnterior !== $produtoAuditoria->imagem_url) {
            $this->apagarImagemPropria($imagemAnterior);
        }
        $produtoAuditoria->load(['departamento', 'secao', 'marca', 'nivelExibicao']);

        return response()->json([
            'produto' => new ProdutoAuditoriaResource($produtoAuditoria),
        ]);
    }

    /**
     * Upload da foto do produto pelo admin — alternativa a colar uma URL externa. Grava no
     * mesmo disco das fotos de registro de visita (`config('filesystems.default')`) e salva em
     * `imagem_url` a URL absoluta da rota pública `imagem` abaixo, então todo consumidor do campo
     * (app mobile com `<Image uri>`, admin com `<img>`, planograma) continua igual, sem saber
     * se a foto é externa ou nossa. Nome aleatório a cada envio: a URL muda junto com a foto
     * (sem cache velho) e não dá pra adivinhar a de outro produto/empresa. A foto anterior,
     * se também foi enviada por aqui, é apagada.
     */
    public function uploadImagem(Request $request, ProdutoAuditoria $produtoAuditoria): JsonResponse
    {
        $request->validate([
            'imagem' => ['required', 'file', 'max:5120', 'mimes:jpg,jpeg,png,webp'],
        ]);

        $arquivo = $request->file('imagem');
        $nomeArquivo = Str::uuid().'.'.($arquivo->extension() ?: 'jpg');
        $arquivo->storeAs('produtos', $nomeArquivo, config('filesystems.default'));

        $imagemAnterior = $produtoAuditoria->imagem_url;
        $produtoAuditoria->update(['imagem_url' => url("/api/produtos-auditoria/imagens/{$nomeArquivo}")]);
        $this->apagarImagemPropria($imagemAnterior);
        $produtoAuditoria->load(['departamento', 'secao', 'marca', 'nivelExibicao']);

        return response()->json([
            'produto' => new ProdutoAuditoriaResource($produtoAuditoria),
        ]);
    }

    /**
     * Serve a foto enviada por `uploadImagem`. Pública de propósito (fora do auth:sanctum):
     * foto de catálogo não é sensível, e `imagem_url` é lido como URL comum em vários lugares —
     * o app mobile usa `<Image source={{ uri }}>` sem header (ver ImagemAutenticada.tsx sobre
     * por que rota autenticada no RN é problema). O nome é um UUID aleatório.
     */
    public function imagem(string $arquivo): StreamedResponse
    {
        $caminho = "produtos/{$arquivo}";
        $disco = Storage::disk(config('filesystems.default'));
        abort_unless($disco->exists($caminho), 404);

        // Nome muda a cada envio, então o conteúdo de uma URL nunca muda — cache longo.
        return $disco->response($caminho, headers: ['Cache-Control' => 'public, max-age=31536000, immutable']);
    }

    private function apagarImagemPropria(?string $url): void
    {
        $caminho = self::caminhoImagemPropria($url);
        if ($caminho) {
            Storage::disk(config('filesystems.default'))->delete($caminho);
        }
    }

    /**
     * Caminho no storage se a URL aponta pra uma foto enviada por `uploadImagem`; null se é URL
     * externa. Compara só o path (não o host) — a URL guardada é absoluta com o APP_URL da época.
     */
    public static function caminhoImagemPropria(?string $url): ?string
    {
        $path = $url ? parse_url($url, PHP_URL_PATH) : null;

        return is_string($path) && preg_match('#^/api/produtos-auditoria/imagens/([0-9a-f-]{36}\.[a-z0-9]+)$#', $path, $m)
            ? "produtos/{$m[1]}"
            : null;
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

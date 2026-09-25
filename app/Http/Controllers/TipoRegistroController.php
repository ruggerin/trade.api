<?php

namespace App\Http\Controllers;

use App\Http\Requests\TipoRegistro\MoverTipoRegistroRequest;
use App\Http\Requests\TipoRegistro\StoreTipoRegistroRequest;
use App\Http\Requests\TipoRegistro\UpdateTipoRegistroRequest;
use App\Http\Resources\TipoRegistroResource;
use App\Models\CampanhaAuditoria;
use App\Models\CampoTipoRegistro;
use App\Models\DepartamentoAuditoria;
use App\Models\Empresa;
use App\Models\MarcaAuditoria;
use App\Models\ProdutoAuditoria;
use App\Models\SecaoAuditoria;
use App\Models\TipoRegistro;
use App\Models\TipoRegistroSecaoExcecao;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TipoRegistroController extends Controller
{
    // Relações do campo carregadas em toda resposta com TipoRegistroResource — inclui o
    // condicional (decisão 7) e o sortimento (decisão 3), ambos de
    // docs/20-FORMULARIO-DINAMICO-CAMPANHA.md.
    private const RELACOES_CAMPOS = [
        'campos.dependeDe', 'campos.sortimentoSecao', 'campos.sortimentoDepartamento',
        'campos.sortimentoMarca', 'campos.produtosFixos',
    ];

    public function index(Request $request): JsonResponse
    {
        $tipos = TipoRegistro::query()
            ->when($request->has('ativo'), fn ($query) => $query->where('ativo', $request->boolean('ativo')))
            // Só tem efeito prático pro SUPERADMIN — ver DepartamentoAuditoriaController::index.
            ->when(
                $request->filled('empresa_uuid'),
                fn ($query) => $query->where('empresa_id', Empresa::where('uuid', $request->string('empresa_uuid'))->value('id')),
            )
            // "Formulário desta campanha" (Fase 3, autoria embutida) — a tela de Campanha lista
            // só os tipos vinculados a ela, ver docs/20-FORMULARIO-DINAMICO-CAMPANHA.md §3.
            ->when(
                $request->filled('campanha_auditoria_uuid'),
                fn ($query) => $query->whereHas(
                    'campanhaAuditoria',
                    fn ($q) => $q->where('uuid', $request->string('campanha_auditoria_uuid')),
                ),
            )
            ->with([...self::RELACOES_CAMPOS, 'empresa', 'campanhaAuditoria', 'excecoesGranularidade.secao'])
            // Sequência de exibição escolhida pelo gestor (ver mover() abaixo) — mesma ordem
            // pro admin e pro mobile, que consome este mesmo endpoint. `descricao` só entra
            // como desempate defensivo (na operação normal `ordem` já é único por empresa).
            ->orderBy('ordem')
            ->orderBy('descricao')
            ->paginate();

        return response()->json([
            'tipos_registro' => TipoRegistroResource::collection($tipos->items()),
            'meta' => [
                'current_page' => $tipos->currentPage(),
                'last_page' => $tipos->lastPage(),
                'per_page' => $tipos->perPage(),
                'total' => $tipos->total(),
            ],
        ]);
    }

    /**
     * Busca individual — usada pela página dedicada de edição no admin (não é mais modal, ver
     * docs/25-DIRECIONAMENTO-ORDEM-SERVICO.md e a TipoRegistroFormPage). Leitura aberta, mesmo
     * padrão de index().
     */
    public function show(TipoRegistro $tipoRegistro): JsonResponse
    {
        $tipoRegistro->load([...self::RELACOES_CAMPOS, 'campanhaAuditoria', 'excecoesGranularidade.secao']);

        return response()->json([
            'tipo_registro' => new TipoRegistroResource($tipoRegistro),
        ]);
    }

    public function store(StoreTipoRegistroRequest $request): JsonResponse
    {
        $dados = $request->validated();

        // Transação: `sincronizarCampos`/`sincronizarExcecoesGranularidade` são várias queries
        // depois do `TipoRegistro` já criado — sem isso, uma falha no meio (ex.: um valor de
        // campo rejeitado pelo banco que a validação não pegou) deixa um `TipoRegistro` órfão,
        // sem nenhum campo, em vez de nada ser salvo.
        $tipo = DB::transaction(function () use ($dados) {
            $tipo = TipoRegistro::create([
                'descricao' => $dados['descricao'],
                'icone' => $dados['icone'] ?? null,
                // Novo tipo sempre vai pro fim da lista — mesmo raciocínio de "onde entraria um
                // item novo" em qualquer lista ordenada manualmente. Scoped à empresa pelo global
                // scope de BelongsToEmpresa (mesma query que index() usa).
                'ordem' => (TipoRegistro::max('ordem') ?? -1) + 1,
                'exige_foto' => $dados['exige_foto'] ?? false,
                'permite_vincular_catalogo' => $dados['permite_vincular_catalogo'] ?? false,
                'acao_obrigatoria' => $dados['acao_obrigatoria'] ?? false,
                'escopo_acao' => $dados['escopo_acao'] ?? null,
                'campanha_auditoria_id' => $this->resolverCampanhaAuditoriaId($dados['campanha_auditoria_uuid'] ?? null),
                'granularidade_padrao' => $dados['granularidade_padrao'] ?? null,
                'eh_ruptura' => $dados['eh_ruptura'] ?? false,
                'eh_alerta' => $dados['eh_alerta'] ?? false,
                'usa_pontuacao' => $dados['usa_pontuacao'] ?? false,
                'disponivel_registro_livre' => $dados['disponivel_registro_livre'] ?? true,
            ]);
            $this->sincronizarCampos($tipo, $dados['campos'] ?? []);
            $this->sincronizarExcecoesGranularidade($tipo, $dados['excecoes_granularidade'] ?? []);

            return $tipo;
        });
        $tipo->load([...self::RELACOES_CAMPOS, 'campanhaAuditoria', 'excecoesGranularidade.secao']);

        return response()->json([
            'tipo_registro' => new TipoRegistroResource($tipo),
        ], 201);
    }

    public function update(UpdateTipoRegistroRequest $request, TipoRegistro $tipoRegistro): JsonResponse
    {
        $dados = $request->validated();

        if (array_key_exists('campanha_auditoria_uuid', $dados)) {
            $dados['campanha_auditoria_id'] = $this->resolverCampanhaAuditoriaId($dados['campanha_auditoria_uuid']);
            unset($dados['campanha_auditoria_uuid']);
        }
        // Escopo deixou de ser CAMPANHA — não faz sentido manter a campanha antiga amarrada.
        if (($dados['escopo_acao'] ?? null) !== 'CAMPANHA' && array_key_exists('escopo_acao', $dados)) {
            $dados['campanha_auditoria_id'] = null;
        }

        // Mesma razão da transação em store(): sincronizarCampos/sincronizarExcecoesGranularidade
        // fazem delete-all + recreate — uma falha no meio deixaria o tipo sem NENHUM campo em vez
        // de manter o estado anterior ou salvar o novo por completo.
        DB::transaction(function () use ($tipoRegistro, $dados): void {
            $tipoRegistro->update(collect($dados)->except(['campos', 'excecoes_granularidade'])->all());

            if (array_key_exists('campos', $dados)) {
                $this->sincronizarCampos($tipoRegistro, $dados['campos']);
            }

            if (array_key_exists('excecoes_granularidade', $dados)) {
                $this->sincronizarExcecoesGranularidade($tipoRegistro, $dados['excecoes_granularidade']);
            }
        });

        $tipoRegistro->load([...self::RELACOES_CAMPOS, 'campanhaAuditoria', 'excecoesGranularidade.secao']);

        return response()->json([
            'tipo_registro' => new TipoRegistroResource($tipoRegistro),
        ]);
    }

    private function resolverCampanhaAuditoriaId(?string $campanhaUuid): ?int
    {
        if (! $campanhaUuid) {
            return null;
        }

        return CampanhaAuditoria::where('uuid', $campanhaUuid)->value('id');
    }

    public function destroy(TipoRegistro $tipoRegistro): JsonResponse
    {
        // Soft delete (ativo = false), nunca hard delete — mesmo padrão do resto da API.
        // Registros de visita já feitos com este tipo continuam intactos (tipo_registro_id não
        // é apagado, só marcado como indisponível pra novos registros).
        $tipoRegistro->update(['ativo' => false]);

        return response()->json(status: 204);
    }

    /**
     * Sequência de exibição — troca a `ordem` deste tipo com a do vizinho (anterior/seguinte,
     * conforme `direcao`) em vez de expor o número cru pro front mexer direto (evita duas linhas
     * acabando com a mesma ordem por engano). Sem vizinho nessa direção (já é o primeiro/último),
     * não faz nada — não é erro, só não tem pra onde mover. Ver docs/03-ADMIN-WEB.md.
     */
    public function mover(MoverTipoRegistroRequest $request, TipoRegistro $tipoRegistro): JsonResponse
    {
        $direcao = $request->validated('direcao');

        $vizinho = $direcao === 'cima'
            ? TipoRegistro::where('ordem', '<', $tipoRegistro->ordem)->orderByDesc('ordem')->first()
            : TipoRegistro::where('ordem', '>', $tipoRegistro->ordem)->orderBy('ordem')->first();

        if ($vizinho) {
            DB::transaction(function () use ($tipoRegistro, $vizinho): void {
                $ordemAtual = $tipoRegistro->ordem;
                $tipoRegistro->update(['ordem' => $vizinho->ordem]);
                $vizinho->update(['ordem' => $ordemAtual]);
            });
        }

        return response()->json([
            'tipo_registro' => new TipoRegistroResource(
                $tipoRegistro->fresh()->load([...self::RELACOES_CAMPOS, 'campanhaAuditoria', 'excecoesGranularidade.secao']),
            ),
        ]);
    }

    /**
     * Substitui a lista de campos inteira (delete-all + recreate) em vez de tentar casar item a
     * item — mais simples que reconciliar por chave/id, e a lista costuma ser pequena (poucos
     * campos por tipo). A ordem de exibição no formulário é a própria ordem do array recebido.
     *
     * Duas passadas por causa do campo condicional (`depende_de_chave`, ver decisão 7 de
     * docs/20-FORMULARIO-DINAMICO-CAMPANHA.md): o `id` do campo pai só existe depois de criado,
     * então a 1ª passada cria todos os campos (sem a dependência), a 2ª resolve
     * `depende_de_chave` → `depende_de_campo_id` já com todos os ids conhecidos.
     * `StoreTipoRegistroRequest::withValidator` garante que `depende_de_chave` (quando presente)
     * aponta pra uma chave que existe neste mesmo array, então o `$idsPorChave[...]` abaixo
     * nunca fica sem match.
     */
    private function sincronizarCampos(TipoRegistro $tipo, array $campos): void
    {
        CampoTipoRegistro::where('tipo_registro_id', $tipo->id)->delete();

        $idsPorChave = [];
        $criados = [];
        foreach (array_values($campos) as $indice => $campo) {
            $criado = CampoTipoRegistro::create([
                'tipo_registro_id' => $tipo->id,
                'chave' => $campo['chave'],
                'rotulo' => $campo['rotulo'],
                'tipo_campo' => $campo['tipo_campo'],
                'opcoes' => $campo['opcoes'] ?? null,
                'obrigatorio' => $campo['obrigatorio'] ?? false,
                'ordem' => $indice,
                'limite_dias_retroativos' => $campo['limite_dias_retroativos'] ?? null,
                // Só usado quando tipo_campo = SORTIMENTO (decisão 3 de
                // docs/20-FORMULARIO-DINAMICO-CAMPANHA.md) — fica tudo NULL/false pros demais tipos.
                'sortimento_origem' => $campo['sortimento_origem'] ?? null,
                'sortimento_tipo_vinculo' => $campo['sortimento_tipo_vinculo'] ?? null,
                'sortimento_secao_id' => isset($campo['sortimento_secao_uuid'])
                    ? SecaoAuditoria::where('uuid', $campo['sortimento_secao_uuid'])->value('id')
                    : null,
                'sortimento_departamento_id' => isset($campo['sortimento_departamento_uuid'])
                    ? DepartamentoAuditoria::where('uuid', $campo['sortimento_departamento_uuid'])->value('id')
                    : null,
                'sortimento_marca_id' => isset($campo['sortimento_marca_uuid'])
                    ? MarcaAuditoria::where('uuid', $campo['sortimento_marca_uuid'])->value('id')
                    : null,
                'confirmar_ruptura_ausentes' => $campo['confirmar_ruptura_ausentes'] ?? false,
            ]);

            // Lista curada (sortimento_origem = FIXO) — mesmo raciocínio de "tipo planograma".
            if (($campo['sortimento_origem'] ?? null) === 'FIXO' && ! empty($campo['sortimento_produtos_uuids'])) {
                $produtoIds = ProdutoAuditoria::whereIn('uuid', $campo['sortimento_produtos_uuids'])->pluck('id');
                $criado->produtosFixos()->sync($produtoIds);
            }

            $idsPorChave[$campo['chave']] = $criado->id;
            $criados[] = [$criado, $campo['depende_de_chave'] ?? null, $campo['depende_de_valor'] ?? null];
        }

        foreach ($criados as [$criado, $dependeDeChave, $dependeDeValor]) {
            if ($dependeDeChave !== null) {
                $criado->update([
                    'depende_de_campo_id' => $idsPorChave[$dependeDeChave] ?? null,
                    'depende_de_valor' => $dependeDeValor,
                ]);
            }
        }
    }

    /**
     * Duplicar (decisão 6 de docs/20-FORMULARIO-DINAMICO-CAMPANHA.md) — clona um `TipoRegistro`
     * existente com todos os campos (inclusive condicional e sortimento) como ponto de partida
     * de um formulário novo; cada cópia fica independente depois, sem "modelo vivo" sincronizado.
     * Ação obrigatória/campanha NUNCA são copiadas — a cópia nasce solta, o gestor decide se e
     * onde vincular (evita uma cópia "roubar" a pendência obrigatória da campanha original).
     */
    public function duplicar(TipoRegistro $tipoRegistro): JsonResponse
    {
        $tipoRegistro->load(['campos.dependeDe', 'campos.produtosFixos', 'excecoesGranularidade']);

        $copia = DB::transaction(function () use ($tipoRegistro) {
            $copia = TipoRegistro::create([
                'descricao' => $tipoRegistro->descricao.' (cópia)',
                'icone' => $tipoRegistro->icone,
                'ordem' => (TipoRegistro::max('ordem') ?? -1) + 1,
                'exige_foto' => $tipoRegistro->exige_foto,
                'permite_vincular_catalogo' => $tipoRegistro->permite_vincular_catalogo,
                'acao_obrigatoria' => false,
                'escopo_acao' => null,
                'campanha_auditoria_id' => null,
                'granularidade_padrao' => $tipoRegistro->granularidade_padrao,
                'eh_ruptura' => $tipoRegistro->eh_ruptura,
                'eh_alerta' => $tipoRegistro->eh_alerta,
                'usa_pontuacao' => $tipoRegistro->usa_pontuacao,
                'disponivel_registro_livre' => $tipoRegistro->disponivel_registro_livre,
            ]);

            $idsPorChave = [];
            $criados = [];
            foreach ($tipoRegistro->campos as $original) {
                $criado = CampoTipoRegistro::create([
                    'tipo_registro_id' => $copia->id,
                    'chave' => $original->chave,
                    'rotulo' => $original->rotulo,
                    'tipo_campo' => $original->tipo_campo,
                    'opcoes' => $original->opcoes,
                    'obrigatorio' => $original->obrigatorio,
                    'ordem' => $original->ordem,
                    'limite_dias_retroativos' => $original->limite_dias_retroativos,
                    'sortimento_origem' => $original->sortimento_origem,
                    'sortimento_tipo_vinculo' => $original->sortimento_tipo_vinculo,
                    'sortimento_secao_id' => $original->sortimento_secao_id,
                    'sortimento_departamento_id' => $original->sortimento_departamento_id,
                    'sortimento_marca_id' => $original->sortimento_marca_id,
                    'confirmar_ruptura_ausentes' => $original->confirmar_ruptura_ausentes,
                ]);
                $criado->produtosFixos()->sync($original->produtosFixos->pluck('id'));

                $idsPorChave[$original->chave] = $criado->id;
                $criados[] = [$criado, $original->dependeDe?->chave, $original->depende_de_valor];
            }

            foreach ($criados as [$criado, $dependeDeChave, $dependeDeValor]) {
                if ($dependeDeChave !== null) {
                    $criado->update([
                        'depende_de_campo_id' => $idsPorChave[$dependeDeChave] ?? null,
                        'depende_de_valor' => $dependeDeValor,
                    ]);
                }
            }

            foreach ($tipoRegistro->excecoesGranularidade as $excecao) {
                TipoRegistroSecaoExcecao::create([
                    'tipo_registro_id' => $copia->id,
                    'secao_auditoria_id' => $excecao->secao_auditoria_id,
                    'granularidade' => $excecao->granularidade,
                ]);
            }

            return $copia;
        });

        $copia->load([...self::RELACOES_CAMPOS, 'campanhaAuditoria', 'excecoesGranularidade.secao']);

        return response()->json([
            'tipo_registro' => new TipoRegistroResource($copia),
        ], 201);
    }

    /**
     * Mesmo padrão de sincronizarCampos: substitui a lista inteira de exceções (delete-all +
     * recreate) em vez de reconciliar item a item — lista pequena, sempre enviada por completo.
     * Ver docs/16-GRANULARIDADE-CHECKLIST-AUDITORIA.md §4.
     */
    private function sincronizarExcecoesGranularidade(TipoRegistro $tipo, array $excecoes): void
    {
        TipoRegistroSecaoExcecao::where('tipo_registro_id', $tipo->id)->delete();

        foreach ($excecoes as $excecao) {
            $secaoId = SecaoAuditoria::where('uuid', $excecao['secao_uuid'])->value('id');
            if (! $secaoId) {
                continue;
            }

            TipoRegistroSecaoExcecao::create([
                'tipo_registro_id' => $tipo->id,
                'secao_auditoria_id' => $secaoId,
                'granularidade' => $excecao['granularidade'],
            ]);
        }
    }
}

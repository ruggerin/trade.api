<?php

namespace App\Http\Controllers;

use App\Http\Requests\TipoRegistro\MoverTipoRegistroRequest;
use App\Http\Requests\TipoRegistro\StoreTipoRegistroRequest;
use App\Http\Requests\TipoRegistro\UpdateTipoRegistroRequest;
use App\Http\Resources\TipoRegistroResource;
use App\Models\CampanhaAuditoria;
use App\Models\CampoTipoRegistro;
use App\Models\Empresa;
use App\Models\SecaoAuditoria;
use App\Models\TipoRegistro;
use App\Models\TipoRegistroSecaoExcecao;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TipoRegistroController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $tipos = TipoRegistro::query()
            ->when($request->has('ativo'), fn ($query) => $query->where('ativo', $request->boolean('ativo')))
            // Só tem efeito prático pro SUPERADMIN — ver DepartamentoAuditoriaController::index.
            ->when(
                $request->filled('empresa_uuid'),
                fn ($query) => $query->where('empresa_id', Empresa::where('uuid', $request->string('empresa_uuid'))->value('id')),
            )
            ->with(['campos.dependeDe', 'empresa', 'campanhaAuditoria', 'excecoesGranularidade.secao'])
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
            ]);
            $this->sincronizarCampos($tipo, $dados['campos'] ?? []);
            $this->sincronizarExcecoesGranularidade($tipo, $dados['excecoes_granularidade'] ?? []);

            return $tipo;
        });
        $tipo->load(['campos.dependeDe', 'campanhaAuditoria', 'excecoesGranularidade.secao']);

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

        $tipoRegistro->load(['campos.dependeDe', 'campanhaAuditoria', 'excecoesGranularidade.secao']);

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
                $tipoRegistro->fresh()->load(['campos.dependeDe', 'campanhaAuditoria', 'excecoesGranularidade.secao']),
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
            ]);
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

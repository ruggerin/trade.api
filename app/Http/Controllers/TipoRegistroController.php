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
            ->with(['campos', 'empresa', 'campanhaAuditoria', 'excecoesGranularidade.secao'])
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
        ]);
        $this->sincronizarCampos($tipo, $dados['campos'] ?? []);
        $this->sincronizarExcecoesGranularidade($tipo, $dados['excecoes_granularidade'] ?? []);
        $tipo->load(['campos', 'campanhaAuditoria', 'excecoesGranularidade.secao']);

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

        $tipoRegistro->update(collect($dados)->except(['campos', 'excecoes_granularidade'])->all());

        if (array_key_exists('campos', $dados)) {
            $this->sincronizarCampos($tipoRegistro, $dados['campos']);
        }

        if (array_key_exists('excecoes_granularidade', $dados)) {
            $this->sincronizarExcecoesGranularidade($tipoRegistro, $dados['excecoes_granularidade']);
        }

        $tipoRegistro->load(['campos', 'campanhaAuditoria', 'excecoesGranularidade.secao']);

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
                $tipoRegistro->fresh()->load(['campos', 'campanhaAuditoria', 'excecoesGranularidade.secao']),
            ),
        ]);
    }

    /**
     * Substitui a lista de campos inteira (delete-all + recreate) em vez de tentar casar item a
     * item — mais simples que reconciliar por chave/id, e a lista costuma ser pequena (poucos
     * campos por tipo). A ordem de exibição no formulário é a própria ordem do array recebido.
     */
    private function sincronizarCampos(TipoRegistro $tipo, array $campos): void
    {
        CampoTipoRegistro::where('tipo_registro_id', $tipo->id)->delete();

        foreach (array_values($campos) as $indice => $campo) {
            CampoTipoRegistro::create([
                'tipo_registro_id' => $tipo->id,
                'chave' => $campo['chave'],
                'rotulo' => $campo['rotulo'],
                'tipo_campo' => $campo['tipo_campo'],
                'opcoes' => $campo['opcoes'] ?? null,
                'obrigatorio' => $campo['obrigatorio'] ?? false,
                'ordem' => $indice,
            ]);
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

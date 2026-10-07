<?php

namespace App\Http\Controllers;

use App\Enums\Permissao;
use App\Enums\UserType;
use App\Http\Resources\RelatorioPersonalizadoResource;
use App\Models\Empresa;
use App\Models\MarcaAuditoria;
use App\Models\MotivoNaoExecucao;
use App\Models\MotivoResolucaoAlerta;
use App\Models\ObjetivoVisita;
use App\Models\PontoVenda;
use App\Models\ProdutoAuditoria;
use App\Models\RedeLoja;
use App\Models\RelatorioPersonalizado;
use App\Models\TipoRegistro;
use App\Models\TipoVisita;
use App\Models\Usuario;
use App\Relatorios\ExecutorRelatorio;
use App\Relatorios\Periodo;
use App\Support\Fuso;
use App\Support\RelatoriosPadrao;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Gerador de relatórios — docs/60-GERADOR-DE-RELATORIOS.md §5. Ver e executar: ADMIN/GESTOR (mesmo
 * gate dos relatórios fixos). Criar/editar/excluir/duplicar: permissão
 * `relatorios.personalizados.gerenciar` (na rota). Período e agrupamento por dia no fuso da empresa.
 */
class RelatorioPersonalizadoController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->exigirAdminOuGestor($request);

        $relatorios = RelatorioPersonalizado::query()
            ->with('usuario:id,uuid,nome')
            ->visivelPara($request->user())
            ->withExists(['fixadoPor as fixado_meu' => fn ($q) => $q->where('usuarios.id', $request->user()->id)])
            ->orderByDesc('padrao')
            ->orderBy('nome')
            ->get();

        return response()->json(['data' => RelatorioPersonalizadoResource::collection($relatorios)]);
    }

    public function show(Request $request, RelatorioPersonalizado $relatorio): JsonResponse
    {
        $this->exigirVisivel($request, $relatorio);
        $relatorio->loadExists(['fixadoPor as fixado_meu' => fn ($q) => $q->where('usuarios.id', $request->user()->id)]);

        return response()->json(['data' => new RelatorioPersonalizadoResource($relatorio->load('usuario:id,uuid,nome'))]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->exigirAdminOuGestor($request);
        $dados = $this->validarDados($request);

        $relatorio = RelatorioPersonalizado::create($dados + [
            'usuario_id' => $request->user()->id,
            'padrao' => false,
        ]);

        return response()->json(['data' => new RelatorioPersonalizadoResource($relatorio->load('usuario:id,uuid,nome'))], 201);
    }

    public function update(Request $request, RelatorioPersonalizado $relatorio): JsonResponse
    {
        $this->exigirEditavel($request, $relatorio);
        $relatorio->update($this->validarDados($request));

        // Deixou de ser compartilhado: sai do menu da empresa e do "meu" de quem não é o criador
        // (eles nem enxergam mais o relatório — docs/63 §1.7).
        if (! $relatorio->compartilhado) {
            $relatorio->update(['fixado_empresa' => false, 'ordem_menu' => null]);
            $relatorio->fixadoPor()->wherePivot('usuario_id', '!=', $relatorio->usuario_id)->detach();
        }

        return response()->json(['data' => new RelatorioPersonalizadoResource($relatorio->load('usuario:id,uuid,nome'))]);
    }

    public function destroy(Request $request, RelatorioPersonalizado $relatorio): JsonResponse
    {
        $this->exigirEditavel($request, $relatorio);
        $relatorio->delete();

        return response()->json(status: 204);
    }

    /** Teto de fixados no menu (docs/63 §1.7): o resto fica acessível pela lista do gerador. */
    public const MAX_FIXADOS_EMPRESA = 8;

    public const MAX_FIXADOS_MEU = 5;

    /**
     * Fixados que aparecem no menu do usuário: os da empresa e os dele, sem repetir. Só relatórios
     * que ele ainda enxerga (descompartilhado some sozinho).
     */
    public function menu(Request $request): JsonResponse
    {
        $this->exigirAdminOuGestor($request);
        $usuario = $request->user();

        $daEmpresa = RelatorioPersonalizado::query()
            ->visivelPara($usuario)
            ->where('fixado_empresa', true)
            ->orderByRaw('ordem_menu is null, ordem_menu')
            ->orderBy('nome')
            ->get(['id', 'uuid', 'nome']);

        $meus = $usuario->relatoriosFixados()
            ->visivelPara($usuario)
            ->orderBy('relatorios_fixados_usuario.ordem')
            ->orderBy('relatorios_personalizados.nome')
            ->get(['relatorios_personalizados.id', 'relatorios_personalizados.uuid', 'relatorios_personalizados.nome']);

        $itens = [];
        foreach ($daEmpresa as $r) {
            $itens[$r->id] = ['id' => $r->uuid, 'nome' => $r->nome, 'alcance' => 'empresa'];
        }
        foreach ($meus as $r) {
            $itens[$r->id] ??= ['id' => $r->uuid, 'nome' => $r->nome, 'alcance' => 'meu'];
        }

        return response()->json(['data' => array_values($itens)]);
    }

    /**
     * Fixa/desafixa no menu. `empresa`: exige relatorios.personalizados.gerenciar e relatório
     * padrão ou compartilhado. `meu`: basta enxergar o relatório.
     */
    public function fixar(Request $request, RelatorioPersonalizado $relatorio): JsonResponse
    {
        $this->exigirVisivel($request, $relatorio);
        $dados = $request->validate([
            'alcance' => ['required', Rule::in(['empresa', 'meu'])],
            'fixado' => ['required', 'boolean'],
        ]);
        $usuario = $request->user();

        if ($dados['alcance'] === 'empresa') {
            abort_unless($usuario->temPermissao(Permissao::RELATORIOS_PERSONALIZADOS_GERENCIAR), 403, 'Você não tem permissão para fixar relatórios no menu da empresa.');
            if ($dados['fixado'] && ! $relatorio->padrao && ! $relatorio->compartilhado) {
                abort(422, 'Só relatório padrão ou compartilhado pode ser fixado no menu da empresa.');
            }
            if ($dados['fixado'] && ! $relatorio->fixado_empresa
                && RelatorioPersonalizado::query()->where('fixado_empresa', true)->count() >= self::MAX_FIXADOS_EMPRESA) {
                abort(422, 'O menu da empresa já tem '.self::MAX_FIXADOS_EMPRESA.' relatórios fixados. Desafixe um antes.');
            }
            $relatorio->update([
                'fixado_empresa' => $dados['fixado'],
                'ordem_menu' => $dados['fixado']
                    ? ($relatorio->ordem_menu ?? (int) RelatorioPersonalizado::query()->max('ordem_menu') + 1)
                    : null,
            ]);
        } else {
            $jaFixado = $usuario->relatoriosFixados()->whereKey($relatorio->id)->exists();
            if ($dados['fixado'] && ! $jaFixado) {
                abort_if($usuario->relatoriosFixados()->count() >= self::MAX_FIXADOS_MEU, 422, 'Seu menu já tem '.self::MAX_FIXADOS_MEU.' relatórios fixados. Desafixe um antes.');
                $ordem = (int) $usuario->relatoriosFixados()->max('relatorios_fixados_usuario.ordem') + 1;
                $usuario->relatoriosFixados()->attach($relatorio->id, ['ordem' => $ordem]);
            } elseif (! $dados['fixado']) {
                $usuario->relatoriosFixados()->detach($relatorio->id);
            }
        }

        $relatorio->loadExists(['fixadoPor as fixado_meu' => fn ($q) => $q->where('usuarios.id', $usuario->id)]);

        return response()->json(['data' => new RelatorioPersonalizadoResource($relatorio->load('usuario:id,uuid,nome'))]);
    }

    /** Cópia editável — é como o cliente "adapta" um padrão (§5). */
    public function duplicar(Request $request, RelatorioPersonalizado $relatorio): JsonResponse
    {
        $this->exigirVisivel($request, $relatorio);

        $copia = RelatorioPersonalizado::create([
            'usuario_id' => $request->user()->id,
            'nome' => mb_substr('Cópia de '.$relatorio->nome, 0, 120),
            'descricao' => $relatorio->descricao,
            'entidade' => $relatorio->entidade,
            'definicao' => $relatorio->definicao,
            'compartilhado' => false,
            'padrao' => false,
        ]);

        return response()->json(['data' => new RelatorioPersonalizadoResource($copia->load('usuario:id,uuid,nome'))], 201);
    }

    /** Executa o salvo; período, `comparar` e `filtros` (rápidos) sobrescrevem só nesta execução. */
    public function executar(Request $request, RelatorioPersonalizado $relatorio): JsonResponse
    {
        $this->exigirVisivel($request, $relatorio);
        $request->validate([
            'preset' => ['nullable', 'string', Rule::in(Periodo::PRESETS)],
            'data_inicio' => ['nullable', 'date_format:Y-m-d', 'required_with:data_fim'],
            'data_fim' => ['nullable', 'date_format:Y-m-d', 'required_with:data_inicio', 'after_or_equal:data_inicio'],
            'comparar' => ['nullable', 'string', Rule::in([...Periodo::COMPARACOES, 'nenhum'])],
            // Filtros rápidos (JSON): só nesta execução, somados aos filtros salvos com E.
            'filtros' => ['nullable', 'json'],
        ]);

        $definicao = $relatorio->definicao;
        if ($request->filled('filtros')) {
            $definicao['filtros_rapidos'] = json_decode($request->string('filtros')->toString(), true);
        }
        if ($request->filled('data_inicio')) {
            $definicao['periodo'] = ['campo' => $definicao['periodo']['campo'] ?? null, 'inicio' => $request->string('data_inicio')->toString(), 'fim' => $request->string('data_fim')->toString()];
        } elseif ($request->filled('preset')) {
            $definicao['periodo'] = ['campo' => $definicao['periodo']['campo'] ?? null, 'preset' => $request->string('preset')->toString()];
        }
        if ($request->filled('comparar')) {
            $definicao['comparar'] = $request->input('comparar') === 'nenhum' ? null : $request->input('comparar');
        }

        return response()->json(ExecutorRelatorio::executar(ExecutorRelatorio::validar($definicao), $this->fuso($request)));
    }

    /** Pré-visualização do editor: executa uma definição que ainda não foi salva. */
    public function executarDefinicao(Request $request): JsonResponse
    {
        $this->exigirAdminOuGestor($request);
        $request->validate(['definicao' => ['required', 'array']]);

        return response()->json(ExecutorRelatorio::executar(ExecutorRelatorio::validar($request->input('definicao')), $this->fuso($request)));
    }

    /** Campos, operadores e métricas permitidos — alimenta o editor (nada hardcoded no front). */
    public function catalogo(Request $request): JsonResponse
    {
        $this->exigirAdminOuGestor($request);
        $request->validate([
            'entidade' => ['nullable', 'string', Rule::in(array_keys(ExecutorRelatorio::entidades()))],
            // Registros: com um formulário, as perguntas dele entram no catálogo.
            'formulario' => ['nullable', 'string', 'uuid'],
        ]);

        if ($request->filled('entidade')) {
            $entidade = ExecutorRelatorio::entidade($request->string('entidade')->toString(), $request->input('formulario'));

            return response()->json(['data' => $entidade->catalogo()]);
        }

        return response()->json(['data' => collect(ExecutorRelatorio::entidades())
            ->map(fn ($e) => ['chave' => $e->chave(), 'rotulo' => $e->rotulo()])->values()]);
    }

    /**
     * De onde o editor tira as opções de um filtro de relação (`Campo::$fonte`): model e atributo
     * do rótulo. O global scope de empresa de cada model garante que só aparece o da própria empresa.
     */
    private const FONTES = [
        'usuarios' => [Usuario::class, 'nome'],
        'pontos_venda' => [PontoVenda::class, 'fantasia'],
        'redes_lojas' => [RedeLoja::class, 'descricao'],
        'tipos_visita' => [TipoVisita::class, 'descricao'],
        'objetivos_visita' => [ObjetivoVisita::class, 'descricao'],
        'motivos_nao_execucao' => [MotivoNaoExecucao::class, 'descricao'],
        'formularios' => [TipoRegistro::class, 'descricao'],
        'produtos' => [ProdutoAuditoria::class, 'descricao'],
        'marcas' => [MarcaAuditoria::class, 'descricao'],
        'motivos_resolucao' => [MotivoResolucaoAlerta::class, 'descricao'],
    ];

    /** Opções de um filtro: busca por texto, ou os rótulos de uuids já escolhidos (`valores[]`). */
    public function opcoes(Request $request): JsonResponse
    {
        $this->exigirAdminOuGestor($request);
        $dados = $request->validate([
            'fonte' => ['required', 'string', Rule::in(array_keys(self::FONTES))],
            'busca' => ['nullable', 'string', 'max:100'],
            'valores' => ['nullable', 'array', 'max:200'],
            'valores.*' => ['string', 'uuid'],
        ]);
        [$modelo, $rotulo] = self::FONTES[$dados['fonte']];

        $opcoes = $modelo::query()
            ->when($modelo === Usuario::class, fn ($q) => $q->where('user_type', '!=', UserType::SUPERADMIN->value))
            ->when(! empty($dados['valores']), fn ($q) => $q->whereIn('uuid', $dados['valores']))
            ->when(! empty($dados['busca']), fn ($q) => $q->where($rotulo, 'ilike', '%'.addcslashes($dados['busca'], '%_\\').'%'))
            ->orderBy($rotulo)
            ->limit(empty($dados['valores']) ? 50 : 200)
            ->get(['uuid', $rotulo])
            ->map(fn ($m) => ['valor' => $m->uuid, 'rotulo' => (string) $m->{$rotulo}]);

        return response()->json(['data' => $opcoes]);
    }

    /** Botão "Completar relatórios padrão" no detalhe da empresa (superadmin). */
    public function completarPadrao(Empresa $empresa): JsonResponse
    {
        return response()->json(RelatoriosPadrao::completar($empresa));
    }

    /** @return array<string, mixed> */
    private function validarDados(Request $request): array
    {
        $dados = $request->validate([
            'nome' => ['required', 'string', 'max:120'],
            'descricao' => ['nullable', 'string', 'max:500'],
            'compartilhado' => ['sometimes', 'boolean'],
            'definicao' => ['required', 'array'],
        ]);
        $dados['definicao'] = ExecutorRelatorio::validar($dados['definicao']);
        $dados['entidade'] = $dados['definicao']['entidade'];

        return $dados;
    }

    private function fuso(Request $request): string
    {
        return Fuso::daEmpresa($request->user()->empresa);
    }

    private function exigirAdminOuGestor(Request $request): void
    {
        if (! in_array($request->user()->user_type, [UserType::ADMIN, UserType::GESTOR], true)) {
            abort(403, 'Os relatórios são só para ADMIN/GESTOR.');
        }
    }

    private function exigirVisivel(Request $request, RelatorioPersonalizado $relatorio): void
    {
        $this->exigirAdminOuGestor($request);
        $visivel = $relatorio->padrao || $relatorio->compartilhado || $relatorio->usuario_id === $request->user()->id;
        abort_unless($visivel, 404);
    }

    private function exigirEditavel(Request $request, RelatorioPersonalizado $relatorio): void
    {
        $this->exigirVisivel($request, $relatorio);
        if ($relatorio->padrao) {
            abort(403, 'Relatório padrão não pode ser alterado. Duplique para editar.');
        }
        abort_unless($relatorio->editavelPor($request->user()), 403, 'Só quem criou o relatório ou um ADMIN pode alterá-lo.');
    }
}

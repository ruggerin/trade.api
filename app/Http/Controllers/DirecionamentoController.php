<?php

namespace App\Http\Controllers;

use App\Enums\StatusOrdemServico;
use App\Http\Requests\Direcionamento\StoreDirecionamentoRequest;
use App\Http\Requests\Direcionamento\UpdateDirecionamentoRequest;
use App\Http\Resources\DirecionamentoResource;
use App\Models\Direcionamento;
use App\Models\OrdemServico;
use App\Models\PontoVenda;
use App\Models\RedeLoja;
use App\Models\TipoRegistro;
use App\Models\Usuario;
use App\Support\ProgressoDirecionamento;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * Molde que gera Ordem de Serviço em massa — ver docs/25-DIRECIONAMENTO-ORDEM-SERVICO.md.
 * Reaproveita `ordens_servico.gerenciar` (mesmo raciocínio de tipo_visita/agenda_visita já
 * reaproveitarem essa permissão — Direcionamento é dado satélite da mesma responsabilidade).
 */
class DirecionamentoController extends Controller
{
    private const RELACOES = ['pontosVenda', 'redesLoja', 'promotores', 'formularios'];

    public function index(Request $request): JsonResponse
    {
        $query = Direcionamento::query()
            ->when($request->has('ativo'), fn ($q) => $q->where('ativo', $request->boolean('ativo')))
            // formularios carregado pra listagem mostrar os chips — pontosVenda/redesLoja/
            // promotores ficam de fora aqui (só o show() precisa, mais pesado e menos usado).
            ->with('formularios')
            ->withCount('ordensServico');

        $direcionamentos = $query->latest()->paginate();

        return response()->json([
            'direcionamentos' => DirecionamentoResource::collection($direcionamentos->items()),
            'meta' => [
                'current_page' => $direcionamentos->currentPage(),
                'last_page' => $direcionamentos->lastPage(),
                'per_page' => $direcionamentos->perPage(),
                'total' => $direcionamentos->total(),
            ],
        ]);
    }

    public function show(Direcionamento $direcionamento): JsonResponse
    {
        $direcionamento->load(self::RELACOES);

        return response()->json([
            'direcionamento' => new DirecionamentoResource($direcionamento),
            'progresso' => ProgressoDirecionamento::calcular($direcionamento),
        ]);
    }

    public function store(StoreDirecionamentoRequest $request): JsonResponse
    {
        $dados = $request->validated();

        $direcionamento = DB::transaction(function () use ($dados, $request) {
            $direcionamento = Direcionamento::create([
                'empresa_id' => $request->user()->empresa_id,
                'descricao' => $dados['descricao'],
                'vigencia_inicio' => $dados['vigencia_inicio'],
                'vigencia_fim' => $dados['vigencia_fim'],
            ]);

            $this->sincronizarFiltros($direcionamento, $dados);
            $this->sincronizarFormularios($direcionamento, $dados['formularios']);

            return $direcionamento;
        });

        // Geração síncrona da primeira leva — "Dia dos Pais" não espera o cron do dia seguinte,
        // ver docs/25 §2 decisão 3. O reforço diário (routes/console.php) cobre quem virar
        // elegível depois.
        Artisan::call('ordens-servico:gerar-por-direcionamento', ['direcionamento' => $direcionamento->uuid]);

        $direcionamento->load(self::RELACOES);

        return response()->json([
            'direcionamento' => new DirecionamentoResource($direcionamento),
            'progresso' => ProgressoDirecionamento::calcular($direcionamento),
        ], 201);
    }

    public function update(UpdateDirecionamentoRequest $request, Direcionamento $direcionamento): JsonResponse
    {
        $dados = $request->validated();
        $estavaAtivo = $direcionamento->ativo;

        DB::transaction(function () use ($dados, $direcionamento) {
            $direcionamento->update(array_intersect_key($dados, array_flip(['descricao', 'vigencia_inicio', 'vigencia_fim', 'ativo'])));

            if (array_key_exists('ponto_venda_uuids', $dados) || array_key_exists('rede_loja_uuids', $dados) || array_key_exists('promotor_uuids', $dados)) {
                $this->sincronizarFiltros($direcionamento, $dados);
            }

            if (array_key_exists('formularios', $dados)) {
                $this->sincronizarFormularios($direcionamento, $dados['formularios']);
            }
        });

        // ativo: true -> false cancela em cascata o que ainda não começou (decisão 6) — nunca
        // mexe em OS EM_ANDAMENTO/CONCLUIDA, só o que ninguém tocou ainda.
        if ($estavaAtivo && array_key_exists('ativo', $dados) && ! $dados['ativo']) {
            OrdemServico::withoutGlobalScopes()
                ->where('direcionamento_id', $direcionamento->id)
                ->where('status', StatusOrdemServico::PENDENTE)
                ->update(['status' => StatusOrdemServico::CANCELADA]);
        } elseif ($direcionamento->ativo) {
            // Continua ativo (ou acabou de nascer editado antes de ativar) — reflete mudança de
            // filtro/formulário na hora, mesma geração síncrona do store().
            Artisan::call('ordens-servico:gerar-por-direcionamento', ['direcionamento' => $direcionamento->uuid]);
        }

        $direcionamento->load(self::RELACOES);

        return response()->json([
            'direcionamento' => new DirecionamentoResource($direcionamento),
            'progresso' => ProgressoDirecionamento::calcular($direcionamento),
        ]);
    }

    private function sincronizarFiltros(Direcionamento $direcionamento, array $dados): void
    {
        if (array_key_exists('ponto_venda_uuids', $dados)) {
            $ids = PontoVenda::whereIn('uuid', $dados['ponto_venda_uuids'] ?? [])->pluck('id');
            $direcionamento->pontosVenda()->sync($ids);
        }
        if (array_key_exists('rede_loja_uuids', $dados)) {
            $ids = RedeLoja::whereIn('uuid', $dados['rede_loja_uuids'] ?? [])->pluck('id');
            $direcionamento->redesLoja()->sync($ids);
        }
        if (array_key_exists('promotor_uuids', $dados)) {
            $ids = Usuario::whereIn('uuid', $dados['promotor_uuids'] ?? [])->pluck('id');
            $direcionamento->promotores()->sync($ids);
        }
    }

    /**
     * Sempre substitui a lista inteira (mesmo padrão de TipoRegistroController::sincronizarCampos)
     * — o front sempre manda o array completo de formulários exigidos, não um diff.
     */
    private function sincronizarFormularios(Direcionamento $direcionamento, array $formularios): void
    {
        $sync = [];
        foreach ($formularios as $formulario) {
            $id = TipoRegistro::where('uuid', $formulario['tipo_registro_uuid'])->value('id');
            $sync[$id] = [
                'obrigatorio' => $formulario['obrigatorio'] ?? true,
                'calcula_percentual_compliance' => $formulario['calcula_percentual_compliance'] ?? false,
            ];
        }
        $direcionamento->formularios()->sync($sync);
    }
}

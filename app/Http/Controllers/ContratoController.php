<?php

namespace App\Http\Controllers;

use App\Enums\UserType;
use App\Http\Requests\Contrato\StoreContratoRequest;
use App\Http\Requests\Contrato\UpdateContratoRequest;
use App\Http\Resources\ContratoHistoricoResource;
use App\Http\Resources\ContratoResource;
use App\Models\Contrato;
use App\Models\ContratoHistorico;
use App\Models\Empresa;
use App\Models\PontoVenda;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ContratoController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $contratos = Contrato::query()
            ->when($request->has('ativo'), fn ($query) => $query->where('ativo', $request->boolean('ativo')))
            ->when($request->filled('tipo'), fn ($query) => $query->where('tipo', $request->string('tipo')))
            ->when(
                $request->filled('ponto_venda_uuid'),
                fn ($query) => $query->whereHas(
                    'pontoVenda',
                    fn ($q) => $q->where('pontos_venda.uuid', $request->string('ponto_venda_uuid')),
                ),
            )
            // Só tem efeito prático pro SUPERADMIN — BelongsToEmpresa já restringe ADMIN/GESTOR
            // à própria empresa, ver docs/02-API-BACKEND.md e UsuarioController::index.
            ->when(
                $request->filled('empresa_uuid'),
                fn ($query) => $query->where('empresa_id', Empresa::where('uuid', $request->string('empresa_uuid'))->value('id')),
            )
            ->with(['pontoVenda', 'empresa'])
            // Eager-load opcional — só quem precisa (tela de Contratos no admin web) paga o
            // custo extra de carregar metas + resumo calculado de cada uma. Ver
            // docs/09-CONTRATO-METAS.md §5.
            ->when($request->boolean('with_metas'), fn ($query) => $query->with(['metas.marca']))
            ->latest('vigencia_fim')
            ->paginate();

        return response()->json([
            'contratos' => ContratoResource::collection($contratos->items()),
            'meta' => [
                'current_page' => $contratos->currentPage(),
                'last_page' => $contratos->lastPage(),
                'per_page' => $contratos->perPage(),
                'total' => $contratos->total(),
            ],
        ]);
    }

    public function show(Contrato $contrato): JsonResponse
    {
        $contrato->load(['pontoVenda', 'empresa', 'metas.marca']);

        return response()->json([
            'contrato' => new ContratoResource($contrato),
        ]);
    }

    public function store(StoreContratoRequest $request): JsonResponse
    {
        $dados = $request->validated();

        // SUPERADMIN escolhe a empresa no formulário (empresa_uuid) — não tem empresa própria
        // pra herdar via BelongsToEmpresa::creating(). ADMIN/GESTOR criam sempre na própria
        // empresa (preenchido automaticamente pelo trait). Mesmo padrão de UsuarioController::store.
        $empresa = $request->user()->user_type === UserType::SUPERADMIN
            ? Empresa::where('uuid', $dados['empresa_uuid'])->firstOrFail()
            : $request->user()->empresa;

        $pontoVendaId = PontoVenda::withoutGlobalScopes()
            ->where('uuid', $dados['ponto_venda_uuid'])
            ->value('id');

        $contrato = Contrato::create([
            'empresa_id' => $empresa->id,
            'ponto_venda_id' => $pontoVendaId,
            'tipo' => $dados['tipo'],
            'descricao' => $dados['descricao'] ?? null,
            'vigencia_inicio' => $dados['vigencia_inicio'],
            'vigencia_fim' => $dados['vigencia_fim'],
        ]);
        $contrato->load(['pontoVenda', 'empresa']);
        $this->registrarHistorico($request, $contrato, 'Contrato criado');

        return response()->json([
            'contrato' => new ContratoResource($contrato),
        ], 201);
    }

    public function update(UpdateContratoRequest $request, Contrato $contrato): JsonResponse
    {
        $dados = $request->validated();

        if (array_key_exists('ponto_venda_uuid', $dados)) {
            $dados['ponto_venda_id'] = PontoVenda::withoutGlobalScopes()
                ->where('uuid', $dados['ponto_venda_uuid'])
                ->value('id');
            unset($dados['ponto_venda_uuid']);
        }

        // Monta as mensagens de histórico ANTES de aplicar o update — precisa comparar contra
        // os valores antigos do model, ainda intactos aqui.
        $mensagens = $this->descreverAlteracoes($contrato, $dados);

        $contrato->update($dados);
        $contrato->load(['pontoVenda', 'empresa']);

        foreach ($mensagens as $mensagem) {
            $this->registrarHistorico($request, $contrato, $mensagem);
        }

        return response()->json([
            'contrato' => new ContratoResource($contrato),
        ]);
    }

    public function destroy(Request $request, Contrato $contrato): JsonResponse
    {
        // Soft delete (ativo = false), nunca hard delete — mesmo padrão do resto da API.
        $contrato->update(['ativo' => false]);
        $this->registrarHistorico($request, $contrato, 'Contrato desativado');

        return response()->json(status: 204);
    }

    /**
     * Upload (ou substituição) do arquivo do contrato assinado — ação separada da criação/
     * edição porque o fluxo real é: cadastra o contrato primeiro, anexa o PDF/foto escaneada
     * depois de conseguir a assinatura física. Mesmo padrão de armazenamento de
     * VisitaRegistroController::store (disco privado, nunca URL pública direta).
     */
    public function uploadArquivo(Request $request, Contrato $contrato): JsonResponse
    {
        $request->validate([
            'arquivo' => ['required', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png'],
        ]);

        $substituindo = $contrato->arquivo_path !== null;

        $arquivo = $request->file('arquivo');
        $nomeArquivo = "{$contrato->uuid}.".($arquivo->extension() ?: 'pdf');
        $arquivo->storeAs('contratos', $nomeArquivo, config('filesystems.default'));
        $contrato->update(['arquivo_path' => "contratos/{$nomeArquivo}"]);
        $contrato->load(['pontoVenda', 'empresa']);
        $this->registrarHistorico($request, $contrato, $substituindo ? 'Arquivo assinado substituído' : 'Arquivo assinado anexado');

        return response()->json([
            'contrato' => new ContratoResource($contrato),
        ]);
    }

    public function arquivo(Contrato $contrato): StreamedResponse
    {
        abort_if(! $contrato->arquivo_path, 404);

        return Storage::disk(config('filesystems.default'))->response($contrato->arquivo_path);
    }

    /**
     * Log de alterações do contrato (visitas do PDV ficam de fora de propósito — o admin web
     * busca isso direto em GET /api/visitas?ponto_venda_uuid=, não duplicado aqui). Ver
     * docs/02-API-BACKEND.md#contratos.
     */
    public function historico(Contrato $contrato): JsonResponse
    {
        $historicos = $contrato->historicos()
            ->with('usuario')
            ->paginate();

        return response()->json([
            'historico' => ContratoHistoricoResource::collection($historicos->items()),
            'meta' => [
                'current_page' => $historicos->currentPage(),
                'last_page' => $historicos->lastPage(),
                'per_page' => $historicos->perPage(),
                'total' => $historicos->total(),
            ],
        ]);
    }

    private function registrarHistorico(Request $request, Contrato $contrato, string $descricao): void
    {
        ContratoHistorico::create([
            'contrato_id' => $contrato->id,
            'usuario_id' => $request->user()->id,
            'descricao' => $descricao,
        ]);
    }

    /**
     * Compara os dados validados contra o estado atual do model e monta mensagens legíveis pra
     * cada campo que efetivamente mudou — não é um diff estruturado, só texto pronto pro
     * histórico (ver docs/02-API-BACKEND.md#contratos).
     *
     * @return string[]
     */
    private function descreverAlteracoes(Contrato $contrato, array $dados): array
    {
        $mensagens = [];

        if (array_key_exists('ponto_venda_id', $dados) && $dados['ponto_venda_id'] !== $contrato->ponto_venda_id) {
            $novoPontoVenda = PontoVenda::withoutGlobalScopes()->find($dados['ponto_venda_id']);
            $mensagens[] = "Ponto de venda alterado para \"{$novoPontoVenda?->fantasia}\"";
        }

        if (array_key_exists('tipo', $dados) && $dados['tipo'] !== $contrato->tipo->value) {
            $mensagens[] = "Tipo alterado de {$contrato->tipo->value} para {$dados['tipo']}";
        }

        if (array_key_exists('descricao', $dados) && $dados['descricao'] !== $contrato->descricao) {
            $mensagens[] = 'Descrição alterada';
        }

        $novoInicio = array_key_exists('vigencia_inicio', $dados) ? Carbon::parse($dados['vigencia_inicio']) : null;
        $novoFim = array_key_exists('vigencia_fim', $dados) ? Carbon::parse($dados['vigencia_fim']) : null;
        $vigenciaMudou = ($novoInicio && $novoInicio->ne($contrato->vigencia_inicio))
            || ($novoFim && $novoFim->ne($contrato->vigencia_fim));
        if ($vigenciaMudou) {
            $inicioExibido = ($novoInicio ?? $contrato->vigencia_inicio)->format('d/m/Y');
            $fimExibido = ($novoFim ?? $contrato->vigencia_fim)->format('d/m/Y');
            $mensagens[] = "Vigência alterada para {$inicioExibido} – {$fimExibido}";
        }

        if (array_key_exists('ativo', $dados) && $dados['ativo'] !== $contrato->ativo) {
            $mensagens[] = $dados['ativo'] ? 'Contrato reativado' : 'Contrato desativado';
        }

        return $mensagens;
    }
}

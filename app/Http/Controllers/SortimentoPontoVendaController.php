<?php

namespace App\Http\Controllers;

use App\Enums\AutonomiaPromotor;
use App\Enums\StatusAprovacao;
use App\Http\Requests\SortimentoPontoVenda\StoreSortimentoPontoVendaRequest;
use App\Http\Resources\SortimentoPontoVendaResource;
use App\Models\DepartamentoAuditoria;
use App\Models\MarcaAuditoria;
use App\Models\PontoVenda;
use App\Models\ProdutoAuditoria;
use App\Models\SecaoAuditoria;
use App\Models\SortimentoPontoVenda;
use App\Support\AutonomiaSortimento;
use Illuminate\Http\JsonResponse;

class SortimentoPontoVendaController extends Controller
{
    private const RELACOES = ['produto.secao', 'departamento', 'secao', 'marca', 'usuario'];

    /**
     * Admin web (`pontos_venda.gerenciar`) — nasce sempre válido (usuario_id/status_aprovacao
     * null). Ver docs/14-SORTIMENTO-PONTO-VENDA.md §3/§4.
     */
    public function store(StoreSortimentoPontoVendaRequest $request, PontoVenda $pontoVenda): JsonResponse
    {
        $item = SortimentoPontoVenda::create([
            ...$this->resolverIds($request->validated()),
            'ponto_venda_id' => $pontoVenda->id,
        ]);
        $item->load(self::RELACOES);

        return response()->json([
            'item' => new SortimentoPontoVendaResource($item),
        ], 201);
    }

    /**
     * Self-service (mobile) — o próprio promotor cresce o sortimento pela visita, sem passar
     * pela permissão `pontos_venda.gerenciar`. Nasce válido (modo `AUTONOMO`, default) ou
     * `PENDENTE` até um gestor decidir (modo `REQUER_APROVACAO`); some do app se `DESABILITADO`.
     * Ver docs/14-SORTIMENTO-PONTO-VENDA.md §9.
     */
    public function adicionarProprio(StoreSortimentoPontoVendaRequest $request, PontoVenda $pontoVenda): JsonResponse
    {
        $usuario = $request->user();
        $autonomia = AutonomiaSortimento::paraVincular($usuario->empresa);

        if ($autonomia === AutonomiaPromotor::DESABILITADO) {
            abort(403, 'Vincular produto ao sortimento não está habilitado para promotores.');
        }

        $item = SortimentoPontoVenda::create([
            ...$this->resolverIds($request->validated()),
            'ponto_venda_id' => $pontoVenda->id,
            'usuario_id' => $usuario->id,
            'status_aprovacao' => $autonomia === AutonomiaPromotor::REQUER_APROVACAO ? StatusAprovacao::PENDENTE : null,
        ]);
        $item->load(self::RELACOES);

        return response()->json([
            'item' => new SortimentoPontoVendaResource($item),
        ], 201);
    }

    public function destroy(PontoVenda $pontoVenda, SortimentoPontoVenda $item): JsonResponse
    {
        // SortimentoPontoVenda não é tenant-aware por conta própria (herda de PontoVenda) —
        // confirma manualmente que o item pertence mesmo ao PDV da rota.
        abort_if($item->ponto_venda_id !== $pontoVenda->id, 404);

        // Hard delete — mesmo raciocínio de CampanhaItem::destroy, é só cobertura, sem
        // consequência histórica em manter a linha.
        $item->delete();

        return response()->json(status: 204);
    }

    /**
     * Gestor aprova um item `PENDENTE` (`pontos_venda.gerenciar`) — mesmo padrão do painel de
     * aprovação de OS. Só limpa status_aprovacao, o item já existe e já é usável.
     */
    public function aprovar(PontoVenda $pontoVenda, SortimentoPontoVenda $item): JsonResponse
    {
        abort_if($item->ponto_venda_id !== $pontoVenda->id, 404);
        abort_if($item->status_aprovacao !== StatusAprovacao::PENDENTE, 422, 'Este item não está pendente.');

        $item->update(['status_aprovacao' => null]);
        $item->load(self::RELACOES);

        return response()->json([
            'item' => new SortimentoPontoVendaResource($item),
        ]);
    }

    /**
     * Gestor rejeita um item `PENDENTE` — apaga a linha (nada mais referencia um item de
     * sortimento por fora dele mesmo, ver docs/14-SORTIMENTO-PONTO-VENDA.md §3).
     */
    public function rejeitar(PontoVenda $pontoVenda, SortimentoPontoVenda $item): JsonResponse
    {
        abort_if($item->ponto_venda_id !== $pontoVenda->id, 404);
        abort_if($item->status_aprovacao !== StatusAprovacao::PENDENTE, 422, 'Este item não está pendente.');

        $item->delete();

        return response()->json(status: 204);
    }

    private function resolverIds(array $dados): array
    {
        return [
            'tipo_item' => $dados['tipo_item'],
            'produto_id' => isset($dados['produto_uuid'])
                ? ProdutoAuditoria::where('uuid', $dados['produto_uuid'])->value('id')
                : null,
            'departamento_id' => isset($dados['departamento_uuid'])
                ? DepartamentoAuditoria::where('uuid', $dados['departamento_uuid'])->value('id')
                : null,
            'secao_id' => isset($dados['secao_uuid'])
                ? SecaoAuditoria::where('uuid', $dados['secao_uuid'])->value('id')
                : null,
            'marca_id' => isset($dados['marca_uuid'])
                ? MarcaAuditoria::where('uuid', $dados['marca_uuid'])->value('id')
                : null,
        ];
    }
}

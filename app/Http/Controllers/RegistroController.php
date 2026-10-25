<?php

namespace App\Http\Controllers;

use App\Enums\UserType;
use App\Models\PontoVenda;
use App\Models\ProdutoAuditoria;
use App\Models\RedeLoja;
use App\Models\TipoRegistro;
use App\Models\Vendedor;
use App\Models\VisitaRegistro;
use App\Support\Fuso;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Tela "Registros" (docs/44-TELA-REGISTROS.md) — lista genérica e crua de VisitaRegistro,
 * cobrindo qualquer TipoRegistro (ruptura, avaria, validade próxima, foto, observação, o que
 * mais for cadastrado), sem agregação. Existe pra resolver um gap real: antes desta tela, um
 * registro só podia ser visto abrindo a visita específica que o originou — não tinha busca nem
 * link direto de outra tela. Todo filtro é espelhado como query param, de propósito, pra permitir
 * link direto de outras telas já filtrado (ex.: "Rupturas por SKU" da Operação do Dia).
 *
 * Mesmo padrão de GaleriaFotosController::index: ADMIN/GESTOR só, isolamento por empresa via
 * whereHas('visita', ...) incondicional (Visita é BelongsToEmpresa, então mesmo sem nenhum
 * filtro ativo esse whereHas já vira um EXISTS escopado pela empresa do usuário autenticado —
 * VisitaRegistro não tem empresa_id próprio).
 */
class RegistroController extends Controller
{
    /**
     * Vendedores ativos pro filtro da tela — rota própria porque GET /vendedores exige
     * vendedores.gerenciar, que quem só vê Registros não tem (mesmo raciocínio de
     * RotaDoDiaController::promotores).
     */
    public function vendedores(): JsonResponse
    {
        return response()->json([
            'vendedores' => Vendedor::query()
                ->where('ativo', true)
                ->orderBy('nome')
                ->get(['uuid', 'nome', 'codigo_externo'])
                ->map(fn (Vendedor $v) => ['id' => $v->uuid, 'nome' => $v->nome, 'codigo_externo' => $v->codigo_externo])
                ->values(),
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $usuario = $request->user();
        if (! in_array($usuario->user_type, [UserType::ADMIN, UserType::GESTOR], true)) {
            abort(403, 'A tela de Registros é só para ADMIN/GESTOR.');
        }

        $tipoRegistroId = $request->filled('tipo_registro_uuid')
            ? TipoRegistro::where('uuid', $request->string('tipo_registro_uuid'))->value('id')
            : null;
        $produtoId = $request->filled('produto_auditoria_uuid')
            ? ProdutoAuditoria::where('uuid', $request->string('produto_auditoria_uuid'))->value('id')
            : null;
        $pontoVendaId = $request->filled('ponto_venda_uuid')
            ? PontoVenda::where('uuid', $request->string('ponto_venda_uuid'))->value('id')
            : null;
        $redeLojaId = $request->filled('rede_loja_uuid')
            ? RedeLoja::where('uuid', $request->string('rede_loja_uuid'))->value('id')
            : null;
        // Código do cliente no ERP (pontos_venda.codigo_externo) e vendedor da loja.
        $codigoCliente = $request->filled('codigo_externo') ? trim($request->string('codigo_externo')->toString()) : null;
        $vendedorId = $request->filled('vendedor_uuid')
            ? Vendedor::where('uuid', $request->string('vendedor_uuid'))->value('id')
            : null;

        $paginado = VisitaRegistro::query()
            ->whereNull('cancelado_em')
            // Filtro explícito de empresa (não só o scope implícito de Visita::BelongsToEmpresa
            // por trás do whereHas) — VisitaRegistro não tem empresa_id próprio, e um whereHas
            // "vazio" sem nenhuma condição própria não é confiável o bastante pra isolamento
            // multi-tenant; melhor garantir aqui do que descobrir depois.
            ->whereHas('visita', function ($q) use ($usuario, $pontoVendaId, $redeLojaId, $codigoCliente, $vendedorId) {
                $q->where('empresa_id', $usuario->empresa_id)
                    ->when($pontoVendaId, fn ($q) => $q->where('ponto_venda_id', $pontoVendaId))
                    ->when(
                        $redeLojaId,
                        fn ($q) => $q->whereHas('pontoVenda', fn ($q) => $q->where('rede_loja_id', $redeLojaId)),
                    )
                    // Começo do código (o ERP às vezes tem zeros à esquerda / sufixo de filial).
                    ->when(
                        $codigoCliente !== null,
                        fn ($q) => $q->whereHas('pontoVenda', fn ($q) => $q->where('codigo_externo', 'ilike', str_replace(['%', '_'], ['\\%', '\\_'], $codigoCliente).'%')),
                    )
                    ->when(
                        $vendedorId,
                        fn ($q) => $q->whereHas('pontoVenda.vendedores', fn ($q) => $q->where('vendedores.id', $vendedorId)),
                    );
            })
            // Período por data local no fuso da empresa (docs/50 §4.3), não meia-noite UTC.
            ->tap(fn ($q) => Fuso::filtrarPeriodo($q, 'created_at', $request->input('data_inicio'), $request->input('data_fim'), Fuso::daEmpresa($request->user()->empresa)))
            ->when($tipoRegistroId, fn ($q) => $q->where('tipo_registro_id', $tipoRegistroId))
            ->when($produtoId, fn ($q) => $q->where('produto_auditoria_id', $produtoId))
            ->when($request->has('ruptura'), fn ($q) => $q->where('ruptura', $request->boolean('ruptura')))
            ->when(
                $request->filled('alerta_status'),
                fn ($q) => $q->whereHas('tipoRegistro', fn ($q) => $q->where('eh_alerta', true))
                    ->when(
                        // ->string() devolve Stringable, não string — comparação com === contra
                        // um literal nunca bateria (bug achado e corrigido durante o teste).
                        $request->string('alerta_status')->toString() === 'aberto',
                        fn ($q) => $q->whereNull('alerta_resolvido_em'),
                        fn ($q) => $q->whereNotNull('alerta_resolvido_em'),
                    ),
            )
            ->with(['visita.pontoVenda.redeLoja', 'visita.pontoVenda.vendedores', 'visita.usuario', 'tipoRegistro', 'produtoAuditoria', 'motivoResolucao'])
            ->latest('created_at')
            // `por_pagina` é opt-in (docs/68): a exportação pagina de 200 em 200 com os mesmos filtros.
            ->paginate($request->filled('por_pagina') ? min(max($request->integer('por_pagina'), 1), 200) : null);

        $paginado->getCollection()->transform(fn (VisitaRegistro $registro) => [
            'id' => $registro->uuid,
            'ocorrido_em' => $registro->created_at,
            'tipo_registro' => [
                'id' => $registro->tipoRegistro->uuid,
                'descricao' => $registro->tipoRegistro->descricao,
                'icone' => $registro->tipoRegistro->icone,
            ],
            'ponto_venda' => $registro->visita->pontoVenda ? [
                'id' => $registro->visita->pontoVenda->uuid,
                'fantasia' => $registro->visita->pontoVenda->fantasia,
                'codigo_externo' => $registro->visita->pontoVenda->codigo_externo,
                // Vendedores da loja (N:N, espelhados do ERP — docs/73).
                'vendedores' => $registro->visita->pontoVenda->vendedores
                    ->map(fn ($v) => ['id' => $v->uuid, 'nome' => $v->nome])
                    ->values(),
                'rede_loja' => $registro->visita->pontoVenda->redeLoja
                    ? ['id' => $registro->visita->pontoVenda->redeLoja->uuid, 'descricao' => $registro->visita->pontoVenda->redeLoja->descricao]
                    : null,
            ] : null,
            'usuario' => $registro->visita->usuario ? [
                'id' => $registro->visita->usuario->uuid,
                'nome' => $registro->visita->usuario->nome,
            ] : null,
            'produto_auditoria' => $registro->produtoAuditoria
                ? ['id' => $registro->produtoAuditoria->uuid, 'descricao' => $registro->produtoAuditoria->descricao]
                : null,
            'observacao' => $registro->observacao,
            // Resolvido aqui, não no front: só tipo_registro com eh_alerta=true tem estado de
            // alerta — qualquer outro tipo (Foto, Observação livre) sempre volta null, mesmo que
            // alerta_resolvido_em nunca tenha sido setado pra ele (não significaria "aberto").
            'status' => $registro->tipoRegistro->eh_alerta
                ? ($registro->alerta_resolvido_em ? 'resolvido' : 'aberto')
                : null,
            // Motivo do fechamento (docs/56) — só relevante quando status = 'resolvido'.
            'motivo' => $registro->motivoResolucao
                ? ['id' => $registro->motivoResolucao->uuid, 'descricao' => $registro->motivoResolucao->descricao]
                : null,
            'motivo_texto' => $registro->alerta_motivo_texto,
            // Uuid da visita de origem — a razão de ser desta tela (ver docs/44-TELA-REGISTROS.md).
            'visita_id' => $registro->visita->uuid,
        ]);

        return response()->json([
            'registros' => $paginado->items(),
            'meta' => [
                'current_page' => $paginado->currentPage(),
                'last_page' => $paginado->lastPage(),
                'per_page' => $paginado->perPage(),
                'total' => $paginado->total(),
            ],
        ]);
    }
}

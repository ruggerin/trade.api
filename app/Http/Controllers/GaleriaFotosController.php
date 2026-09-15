<?php

namespace App\Http\Controllers;

use App\Enums\UserType;
use App\Http\Resources\VisitaRegistroResource;
use App\Models\DepartamentoAuditoria;
use App\Models\MarcaAuditoria;
use App\Models\PontoVenda;
use App\Models\ProdutoAuditoria;
use App\Models\RamoAtividade;
use App\Models\RedeLoja;
use App\Models\SecaoAuditoria;
use App\Models\TipoRegistro;
use App\Models\Usuario;
use App\Models\VisitaRegistro;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Galeria de Fotos — grade só de fotos (não timeline de eventos, ver AtividadeController),
 * filtrável por período/tipo de registro/departamento/seção/marca/produto/loja/rede de lojas/
 * ramo de atividade/promotor/ruptura. Ver docs/23-GALERIA-DE-FOTOS.md.
 *
 * Diferente do Atividades (que mescla duas fontes heterogêneas em memória), aqui é uma fonte só
 * — `VisitaRegistro` com pelo menos 1 foto — o que permite paginação de banco de verdade
 * (`paginate()`), não `forPage()` sobre coleção em memória.
 */
class GaleriaFotosController extends Controller
{
    private const POR_PAGINA = 24;

    public function index(Request $request): JsonResponse
    {
        $usuario = $request->user();
        if (! in_array($usuario->user_type, [UserType::ADMIN, UserType::GESTOR], true)) {
            abort(403, 'A Galeria de Fotos é só para ADMIN/GESTOR.');
        }

        $tipoRegistroId = $request->filled('tipo_registro_uuid')
            ? TipoRegistro::where('uuid', $request->string('tipo_registro_uuid'))->value('id')
            : null;
        $departamentoId = $request->filled('departamento_uuid')
            ? DepartamentoAuditoria::where('uuid', $request->string('departamento_uuid'))->value('id')
            : null;
        $secaoId = $request->filled('secao_uuid')
            ? SecaoAuditoria::where('uuid', $request->string('secao_uuid'))->value('id')
            : null;
        $marcaId = $request->filled('marca_uuid')
            ? MarcaAuditoria::where('uuid', $request->string('marca_uuid'))->value('id')
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
        $ramoAtividadeId = $request->filled('ramo_atividade_uuid')
            ? RamoAtividade::where('uuid', $request->string('ramo_atividade_uuid'))->value('id')
            : null;
        $usuarioFiltroId = $request->filled('usuario_uuid')
            ? Usuario::withoutGlobalScopes()->where('uuid', $request->string('usuario_uuid'))->value('id')
            : null;

        $paginado = VisitaRegistro::query()
            ->whereNull('cancelado_em')
            ->whereHas('imagens')
            // Unconditional de propósito (não `when()`) — é ISSO que aplica o isolamento por
            // empresa, mesmo sem nenhum filtro de loja/rede/ramo/promotor marcado: `Visita` é
            // BelongsToEmpresa, então este `whereHas` (mesmo com closure "vazia" quando nenhum
            // dos filtros abaixo está ativo) já vira um EXISTS escopado pela empresa do usuário
            // autenticado. Sem isso, VisitaRegistro (que não tem empresa_id próprio) vazaria
            // registro de qualquer empresa pra quem pedisse sem filtro nenhum — mesmo raciocínio
            // de AtividadeController::eventosDeAlerta, só que lá o whereHas já vinha
            // "de graça" porque os filtros de usuario/ponto_venda estavam dentro dele.
            ->whereHas('visita', function ($q) use ($pontoVendaId, $redeLojaId, $ramoAtividadeId, $usuarioFiltroId) {
                $q->when($pontoVendaId, fn ($q) => $q->where('ponto_venda_id', $pontoVendaId))
                    ->when($usuarioFiltroId, fn ($q) => $q->where('usuario_id', $usuarioFiltroId))
                    ->when(
                        $redeLojaId,
                        fn ($q) => $q->whereHas('pontoVenda', fn ($q) => $q->where('rede_loja_id', $redeLojaId)),
                    )
                    ->when(
                        $ramoAtividadeId,
                        fn ($q) => $q->whereHas('pontoVenda', fn ($q) => $q->where('ramo_atividade_id', $ramoAtividadeId)),
                    );
            })
            ->when(
                $request->filled('data_inicio'),
                fn ($q) => $q->whereDate('created_at', '>=', $request->string('data_inicio')),
            )
            ->when(
                $request->filled('data_fim'),
                fn ($q) => $q->whereDate('created_at', '<=', $request->string('data_fim')),
            )
            ->when($tipoRegistroId, fn ($q) => $q->where('tipo_registro_id', $tipoRegistroId))
            ->when($departamentoId, fn ($q) => $q->where('departamento_id', $departamentoId))
            ->when($secaoId, fn ($q) => $q->where('secao_id', $secaoId))
            ->when($marcaId, fn ($q) => $q->where('marca_id', $marcaId))
            ->when($produtoId, fn ($q) => $q->where('produto_auditoria_id', $produtoId))
            ->when($request->has('ruptura'), fn ($q) => $q->where('ruptura', $request->boolean('ruptura')))
            ->with([
                'visita.pontoVenda.redeLoja', 'visita.pontoVenda.ramoAtividade', 'visita.usuario',
                'tipoRegistro.campos', 'produtoAuditoria', 'secao', 'departamento', 'marca', 'imagens',
            ])
            ->latest('created_at')
            ->paginate(self::POR_PAGINA);

        // paginate() devolve Model puro — a resposta precisa de ponto_venda/rede/ramo/usuario ao
        // lado do VisitaRegistroResource (que sozinho não sabe da Visita dona, ver doc 21 §4).
        $paginado->getCollection()->transform(fn (VisitaRegistro $registro) => [
            'id' => $registro->uuid,
            'ocorrido_em' => $registro->created_at,
            'ponto_venda' => $registro->visita->pontoVenda ? [
                'id' => $registro->visita->pontoVenda->uuid,
                'fantasia' => $registro->visita->pontoVenda->fantasia,
                'rede_loja' => $registro->visita->pontoVenda->redeLoja
                    ? ['id' => $registro->visita->pontoVenda->redeLoja->uuid, 'descricao' => $registro->visita->pontoVenda->redeLoja->descricao]
                    : null,
                'ramo_atividade' => $registro->visita->pontoVenda->ramoAtividade
                    ? ['id' => $registro->visita->pontoVenda->ramoAtividade->uuid, 'descricao' => $registro->visita->pontoVenda->ramoAtividade->descricao]
                    : null,
            ] : null,
            // Mesmo shape enxuto de AtividadeController::usuarioParaEvento.
            'usuario' => $registro->visita->usuario ? [
                'id' => $registro->visita->usuario->uuid,
                'nome' => $registro->visita->usuario->nome,
                'foto_url' => $registro->visita->usuario->foto_path ? url("/api/usuarios/{$registro->visita->usuario->uuid}/foto") : null,
            ] : null,
            'registro' => new VisitaRegistroResource($registro),
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

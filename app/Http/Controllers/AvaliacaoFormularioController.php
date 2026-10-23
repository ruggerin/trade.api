<?php

namespace App\Http\Controllers;

use App\Models\Visita;
use App\Models\VisitaRegistro;
use App\Support\Fuso;
use App\Support\Xp\CalculoVisitaXp;
use App\Support\Xp\ReguaXp;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Avaliação de formulário pelo gestor (doc 33, mínimo pra régua do docs/88 §10): a fila do que
 * aguarda avaliação, com a idade de cada um, e aprovar ou reprovar com motivo. Não depende da
 * régua de XP; quando ela está ligada, a decisão recalcula a visita (o XP só conta aprovado).
 * Corrigir os valores do formulário fica pra quando o doc 33 for detalhado.
 */
class AvaliacaoFormularioController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'status' => ['nullable', Rule::in([CalculoVisitaXp::STATUS_PENDENTE, CalculoVisitaXp::STATUS_APROVADO, CalculoVisitaXp::STATUS_REPROVADO])],
            'por_pagina' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $empresa = $request->user()->empresa;
        $fuso = Fuso::daEmpresa($empresa);
        $status = $request->query('status', CalculoVisitaXp::STATUS_PENDENTE);

        $registros = VisitaRegistro::query()
            ->with([
                'tipoRegistro:id,uuid,descricao,xp_iniciativa',
                'produtoAuditoria:id,uuid,descricao',
                'imagens',
                'avaliadoPor:id,nome',
                'visita' => fn ($q) => $q->with(['pontoVenda:id,uuid,fantasia', 'usuario:id,uuid,nome']),
            ])
            ->where('avaliacao_status', $status)
            ->whereNull('cancelado_em')
            // VisitaRegistro não tem empresa_id: o isolamento vem da visita.
            ->whereHas('visita', fn ($q) => $q->where('empresa_id', $empresa->id))
            ->orderBy($status === CalculoVisitaXp::STATUS_PENDENTE ? 'created_at' : 'avaliado_em', $status === CalculoVisitaXp::STATUS_PENDENTE ? 'asc' : 'desc')
            ->paginate($request->integer('por_pagina', 25));

        return response()->json([
            'registros' => $registros->getCollection()->map(fn (VisitaRegistro $r) => [
                'id' => $r->uuid,
                'visita_id' => $r->visita?->uuid,
                'formulario' => $r->tipoRegistro ? ['id' => $r->tipoRegistro->uuid, 'descricao' => $r->tipoRegistro->descricao] : null,
                'produto' => $r->produtoAuditoria?->descricao,
                'loja' => $r->visita?->pontoVenda ? ['id' => $r->visita->pontoVenda->uuid, 'fantasia' => $r->visita->pontoVenda->fantasia] : null,
                'promotor' => $r->visita?->usuario ? ['id' => $r->visita->usuario->uuid, 'nome' => $r->visita->usuario->nome] : null,
                'criado_em' => $r->created_at,
                'idade_dias' => $r->created_at ? (int) $r->created_at->copy()->setTimezone($fuso)->startOfDay()->diffInDays(now($fuso)->startOfDay()) : null,
                'xp' => (int) ($r->tipoRegistro?->xp_iniciativa ?? 0),
                'fotos' => $r->imagens->count(),
                'avaliacao' => [
                    'status' => $r->avaliacao_status,
                    'motivo' => $r->avaliacao_motivo,
                    'por' => $r->avaliadoPor?->nome,
                    'em' => $r->avaliado_em,
                ],
            ])->all(),
            'meta' => [
                'current_page' => $registros->currentPage(),
                'last_page' => $registros->lastPage(),
                'per_page' => $registros->perPage(),
                'total' => $registros->total(),
            ],
        ]);
    }

    public function avaliar(Request $request, Visita $visita, VisitaRegistro $registro): JsonResponse
    {
        abort_if($registro->visita_id !== $visita->id, 404);
        abort_if($registro->avaliacao_status === null, 422, 'Este registro não passa por avaliação.');
        $dados = $request->validate([
            'decisao' => ['required', Rule::in([CalculoVisitaXp::STATUS_APROVADO, CalculoVisitaXp::STATUS_REPROVADO])],
            // Reprovar sempre explica (o promotor vê o motivo e aprende).
            'motivo' => ['nullable', 'required_if:decisao,'.CalculoVisitaXp::STATUS_REPROVADO, 'string', 'max:2000'],
        ]);

        $anterior = $registro->avaliacao_status;
        $registro->update([
            'avaliacao_status' => $dados['decisao'],
            'avaliacao_motivo' => $dados['motivo'] ?? null,
            'avaliado_por_id' => $request->user()->id,
            'avaliado_em' => now(),
        ]);

        $motivo = $anterior !== CalculoVisitaXp::STATUS_PENDENTE
            ? 'Avaliação revista pelo gestor'.(! empty($dados['motivo']) ? ": {$dados['motivo']}" : '.')
            : null;
        ReguaXp::recalcularVisita($visita, $motivo, $request->user()->id);

        return response()->json(['avaliacao' => [
            'id' => $registro->uuid,
            'status' => $registro->avaliacao_status,
            'motivo' => $registro->avaliacao_motivo,
            'em' => $registro->avaliado_em,
        ]]);
    }
}

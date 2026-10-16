<?php

namespace App\Http\Controllers;

use App\Enums\UserType;
use App\Models\AcompanhamentoRegistro;
use App\Models\AcompanhamentoResposta;
use App\Models\TipoRegistro;
use App\Models\Visita;
use App\Models\VisitaRegistro;
use App\Support\Acompanhamentos;
use App\Support\Fuso;
use App\Support\HorarioDoCampo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * "Ainda está lá?" (docs/83): resposta do promotor na visita (app) e acompanhamento pelo admin
 * (lista, linha do tempo e encerramento pelo gestor). Regras em App\Support\Acompanhamentos.
 */
class AcompanhamentoController extends Controller
{
    /** App: resposta na visita. Idempotente pela `idempotency_key` da fila offline. */
    public function responder(Request $request, Visita $visita, AcompanhamentoRegistro $acompanhamento): JsonResponse
    {
        if ($request->user()->user_type === UserType::PROMOTOR && $visita->usuario_id !== $request->user()->id) {
            abort(403, 'Você não tem acesso a esta visita.');
        }
        abort_if($acompanhamento->ponto_venda_id !== $visita->ponto_venda_id, 404);

        $dados = $request->validate([
            'resposta' => ['required', Rule::in([AcompanhamentoResposta::CONTINUA, AcompanhamentoResposta::ACABOU, AcompanhamentoResposta::NAO_CONSEGUI_VER])],
            'idempotency_key' => ['required', 'uuid'],
            'criado_em' => ['nullable', 'date'],
            'registro_uuid' => ['nullable', 'uuid'],
        ]);

        $registro = ! empty($dados['registro_uuid'])
            ? VisitaRegistro::where('uuid', $dados['registro_uuid'])->where('visita_id', $visita->id)->first()
            : null;
        abort_if(! empty($dados['registro_uuid']) && ! $registro, 422, 'O registro desta resposta não é desta visita.');

        $resposta = Acompanhamentos::responder(
            $acompanhamento,
            $visita,
            $request->user(),
            $dados['resposta'],
            HorarioDoCampo::resolver($dados['criado_em'] ?? null, $visita->inicio_data),
            $registro,
            $dados['idempotency_key'],
        );

        $acompanhamento->refresh();

        return response()->json([
            'resposta' => ['id' => $resposta->uuid, 'resposta' => $resposta->resposta, 'aplicada' => $resposta->aplicada],
            'acompanhamento' => ['id' => $acompanhamento->uuid, 'situacao' => $acompanhamento->situacao],
        ], $resposta->wasRecentlyCreated ? 201 : 200);
    }

    /** Admin: lista com situação, loja, dias em aberto e última resposta (docs/83 §5.3). */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'situacao' => ['nullable', 'string'],
            'tipo_registro_uuid' => ['nullable', 'uuid'],
            'ponto_venda_uuid' => ['nullable', 'uuid'],
            'data_inicio' => ['nullable', 'date'],
            'data_fim' => ['nullable', 'date'],
            'registro_uuid' => ['nullable', 'uuid'],
        ]);

        $lista = AcompanhamentoRegistro::query()
            ->when($request->filled('situacao'), fn ($q) => $q->where('situacao', $request->string('situacao')->toString()))
            ->when($request->filled('tipo_registro_uuid'), fn ($q) => $q->whereHas('tipoRegistro', fn ($t) => $t->where('uuid', $request->string('tipo_registro_uuid')->toString())))
            ->when($request->filled('ponto_venda_uuid'), fn ($q) => $q->whereHas('pontoVenda', fn ($p) => $p->where('uuid', $request->string('ponto_venda_uuid')->toString())))
            // Detalhe do registro no admin: "este registro abriu um acompanhamento?".
            ->when($request->filled('registro_uuid'), fn ($q) => $q->whereHas('registroOrigem', fn ($r) => $r->where('uuid', $request->string('registro_uuid')->toString())))
            ->when($request->filled('data_inicio'), fn ($q) => $q->whereDate('created_at', '>=', $request->string('data_inicio')->toString()))
            ->when($request->filled('data_fim'), fn ($q) => $q->whereDate('created_at', '<=', $request->string('data_fim')->toString()))
            ->with(['tipoRegistro:id,uuid,descricao', 'pontoVenda:id,uuid,fantasia', 'registroOrigem:id,uuid,created_at'])
            ->withCount('respostas')
            ->orderByRaw("case when situacao = 'ABERTO' then 0 else 1 end")
            ->latest('id')
            ->paginate($request->filled('por_pagina') ? max(1, min($request->integer('por_pagina'), 200)) : 30);

        return response()->json([
            'acompanhamentos' => $lista->getCollection()->map(fn (AcompanhamentoRegistro $a) => $this->resumo($a))->all(),
            'meta' => [
                'current_page' => $lista->currentPage(),
                'last_page' => $lista->lastPage(),
                'per_page' => $lista->perPage(),
                'total' => $lista->total(),
            ],
        ]);
    }

    /** Admin: linha do tempo — registro original, respostas e registros gerados (docs/83 §3.4). */
    public function show(AcompanhamentoRegistro $acompanhamento): JsonResponse
    {
        $acompanhamento->load([
            'tipoRegistro:id,uuid,descricao,acompanha_rotulo_continua,acompanha_rotulo_acabou',
            'pontoVenda:id,uuid,fantasia', 'encerradoPor:id,nome',
            'registroOrigem.visita:id,uuid,usuario_id', 'registroOrigem.visita.usuario:id,nome', 'registroOrigem.imagens',
            'respostas' => fn ($q) => $q->orderBy('respondido_em'),
            'respostas.usuario:id,nome', 'respostas.visita:id,uuid',
            'respostas.registro.imagens', 'respostas.registro.visita:id,uuid', 'respostas.registro.tipoRegistro.campos',
        ]);
        $origem = $acompanhamento->registroOrigem;
        $origem?->loadMissing('tipoRegistro.campos');

        $registroJson = fn (?VisitaRegistro $r) => $r ? [
            'id' => $r->uuid,
            'criado_em' => $r->created_at?->toIso8601String(),
            'formulario' => $r->tipoRegistro?->descricao,
            'imagens' => $r->visita ? $r->imagens->map(fn ($img) => url("/api/visitas/{$r->visita->uuid}/imagens/{$img->uuid}"))->values() : [],
            'campos_respondidos' => \App\Support\FormatadorValoresCampos::formatar($r->tipoRegistro, $r->valores_campos),
        ] : null;

        return response()->json([
            'acompanhamento' => $this->resumo($acompanhamento) + [
                'encerrado_por' => $acompanhamento->encerradoPor?->nome,
                'rotulo_continua' => $acompanhamento->tipoRegistro?->acompanha_rotulo_continua ?: 'Continua',
                'rotulo_acabou' => $acompanhamento->tipoRegistro?->acompanha_rotulo_acabou ?: 'Acabou',
                'origem' => $registroJson($origem) + ['usuario' => $origem?->visita?->usuario?->nome],
                'respostas' => $acompanhamento->respostas->map(fn (AcompanhamentoResposta $r) => [
                    'id' => $r->uuid,
                    'resposta' => $r->resposta,
                    'aplicada' => $r->aplicada,
                    'respondido_em' => $r->respondido_em?->toIso8601String(),
                    'usuario' => $r->usuario?->nome,
                    'visita_id' => $r->visita?->uuid,
                    'registro' => $registroJson($r->registro),
                ])->values(),
            ],
        ]);
    }

    /** Admin: gestor encerra, com registro de quem e quando (docs/83 §3.3.2). */
    public function encerrar(Request $request, AcompanhamentoRegistro $acompanhamento): JsonResponse
    {
        abort_unless(in_array($request->user()->user_type, [UserType::ADMIN, UserType::GESTOR], true), 403, 'Só gestor encerra acompanhamento.');
        abort_if($acompanhamento->situacao !== AcompanhamentoRegistro::ABERTO, 422, 'Este acompanhamento já está encerrado.');

        Acompanhamentos::encerrarPeloGestor($acompanhamento, $request->user());

        return $this->show($acompanhamento->refresh());
    }

    /** @return array<string, mixed> */
    private function resumo(AcompanhamentoRegistro $a): array
    {
        $fim = $a->encerrado_em ?? now();
        $hoje = Fuso::hoje(Fuso::daLoja($a->pontoVenda))->toDateString();

        return [
            'id' => $a->uuid,
            'situacao' => $a->situacao,
            'formulario' => $a->tipoRegistro ? ['id' => $a->tipoRegistro->uuid, 'descricao' => $a->tipoRegistro->descricao] : null,
            'loja' => $a->pontoVenda ? ['id' => $a->pontoVenda->uuid, 'fantasia' => $a->pontoVenda->fantasia] : null,
            'aberto_em' => $a->registroOrigem?->created_at?->toIso8601String() ?? $a->created_at?->toIso8601String(),
            // Duração do ponto extra / tempo de reposição da ruptura (docs/83 §3.4).
            'dias_em_aberto' => (int) floor(($a->registroOrigem?->created_at ?? $a->created_at)->diffInDays($fim, true)),
            'ultima_resposta_em' => $a->ultima_resposta_em?->toIso8601String(),
            'proxima_verificacao_em' => $a->proxima_verificacao_em?->toDateString(),
            'atrasado' => $a->situacao === AcompanhamentoRegistro::ABERTO && $a->proxima_verificacao_em?->toDateString() < $hoje,
            'encerrado_em' => $a->encerrado_em?->toIso8601String(),
            'expira_em' => $a->expira_em?->toDateString(),
            'respostas_count' => $a->respostas_count ?? $a->respostas()->count(),
        ];
    }
}

<?php

namespace App\Http\Controllers;

use App\Http\Requests\AgendaVisita\StoreAgendaVisitaRequest;
use App\Http\Requests\AgendaVisita\UpdateAgendaVisitaRequest;
use App\Http\Resources\AgendaVisitaResource;
use App\Models\AgendaVisita;
use App\Models\ObjetivoVisita;
use App\Models\PontoVenda;
use App\Models\TipoVisita;
use App\Models\Usuario;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AgendaVisitaController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = AgendaVisita::query()->with(['pontoVenda', 'usuario', 'tipoVisita', 'objetivoVisita']);

        $query
            ->when($request->has('ativo'), fn ($q) => $q->where('ativo', $request->boolean('ativo')))
            ->when(
                $request->filled('ponto_venda_uuid'),
                fn ($q) => $q->whereHas(
                    'pontoVenda',
                    fn ($q2) => $q2->where('pontos_venda.uuid', $request->string('ponto_venda_uuid')),
                ),
            )
            ->when(
                $request->filled('usuario_uuid'),
                fn ($q) => $q->whereHas('usuario', fn ($q2) => $q2->where('usuarios.uuid', $request->string('usuario_uuid'))),
            )
            ->when($request->filled('dia_semana'), fn ($q) => $q->where('dia_semana', $request->integer('dia_semana')));

        $agendasVisita = $query->orderBy('dia_semana')->orderBy('data')->paginate();

        return response()->json([
            'agendas_visita' => AgendaVisitaResource::collection($agendasVisita->items()),
            'meta' => [
                'current_page' => $agendasVisita->currentPage(),
                'last_page' => $agendasVisita->lastPage(),
                'per_page' => $agendasVisita->perPage(),
                'total' => $agendasVisita->total(),
            ],
        ]);
    }

    public function store(StoreAgendaVisitaRequest $request): JsonResponse
    {
        $dados = $request->validated();

        $agendaVisita = AgendaVisita::create([
            'ponto_venda_id' => PontoVenda::withoutGlobalScopes()->where('uuid', $dados['ponto_venda_uuid'])->value('id'),
            'usuario_id' => Usuario::withoutGlobalScopes()->where('uuid', $dados['usuario_uuid'])->value('id'),
            'tipo_visita_id' => ! empty($dados['tipo_visita_uuid'])
                ? TipoVisita::withoutGlobalScopes()->where('uuid', $dados['tipo_visita_uuid'])->value('id')
                : null,
            'objetivo_visita_id' => ! empty($dados['objetivo_visita_uuid'])
                ? ObjetivoVisita::withoutGlobalScopes()->where('uuid', $dados['objetivo_visita_uuid'])->value('id')
                : null,
            'prioridade' => $dados['prioridade'] ?? 'MEDIA',
            'recorrencia' => $dados['recorrencia'],
            'dia_semana' => $dados['dia_semana'] ?? null,
            'data' => $dados['data'] ?? null,
            'horario_previsto' => $dados['horario_previsto'] ?? null,
            'obrigatoria' => $dados['obrigatoria'] ?? true,
            'observacao' => $dados['observacao'] ?? null,
        ]);
        $agendaVisita->load(['pontoVenda', 'usuario', 'tipoVisita', 'objetivoVisita']);

        return response()->json(['agenda_visita' => new AgendaVisitaResource($agendaVisita)], 201);
    }

    public function update(UpdateAgendaVisitaRequest $request, AgendaVisita $agendaVisita): JsonResponse
    {
        $dados = $request->validated();

        if (array_key_exists('ponto_venda_uuid', $dados)) {
            $dados['ponto_venda_id'] = PontoVenda::withoutGlobalScopes()->where('uuid', $dados['ponto_venda_uuid'])->value('id');
            unset($dados['ponto_venda_uuid']);
        }

        if (array_key_exists('usuario_uuid', $dados)) {
            $dados['usuario_id'] = Usuario::withoutGlobalScopes()->where('uuid', $dados['usuario_uuid'])->value('id');
            unset($dados['usuario_uuid']);
        }

        if (array_key_exists('tipo_visita_uuid', $dados)) {
            $dados['tipo_visita_id'] = $dados['tipo_visita_uuid']
                ? TipoVisita::withoutGlobalScopes()->where('uuid', $dados['tipo_visita_uuid'])->value('id')
                : null;
            unset($dados['tipo_visita_uuid']);
        }

        if (array_key_exists('objetivo_visita_uuid', $dados)) {
            $dados['objetivo_visita_id'] = $dados['objetivo_visita_uuid']
                ? ObjetivoVisita::withoutGlobalScopes()->where('uuid', $dados['objetivo_visita_uuid'])->value('id')
                : null;
            unset($dados['objetivo_visita_uuid']);
        }

        // Trocar de recorrência limpa o campo do outro tipo — evita ficar com dia_semana e data
        // preenchidos ao mesmo tempo depois de uma edição.
        if (($dados['recorrencia'] ?? $agendaVisita->recorrencia->value) === 'SEMANAL') {
            $dados['data'] = null;
        } elseif (array_key_exists('recorrencia', $dados)) {
            $dados['dia_semana'] = null;
        }

        $agendaVisita->update($dados);
        $agendaVisita->load(['pontoVenda', 'usuario', 'tipoVisita', 'objetivoVisita']);

        return response()->json(['agenda_visita' => new AgendaVisitaResource($agendaVisita)]);
    }

    public function destroy(AgendaVisita $agendaVisita): JsonResponse
    {
        // Soft delete (ativo = false) — OS já geradas por esta agenda mantêm o vínculo intacto,
        // só não gera mais nenhuma nova.
        $agendaVisita->update(['ativo' => false]);

        return response()->json(status: 204);
    }
}

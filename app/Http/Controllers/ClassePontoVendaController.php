<?php

namespace App\Http\Controllers;

use App\Models\ClassePontoVenda;
use App\Models\PontoVenda;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Classes de loja da régua de XP (docs/88 §4): cadastro da empresa, a padrão marcada no próprio
 * cadastro e a sugestão do sistema que o gestor aceita (o tempo nunca muda a classe sozinho).
 */
class ClassePontoVendaController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $classes = ClassePontoVenda::query()
            ->when($request->has('ativo'), fn ($q) => $q->where('ativo', $request->boolean('ativo')))
            ->withCount('pontosVenda')
            ->orderBy('ordem')->orderByDesc('xp_base')
            ->get();

        return response()->json(['classes' => $classes->map(fn (ClassePontoVenda $c) => $this->json($c))->all()]);
    }

    public function store(Request $request): JsonResponse
    {
        $dados = $this->validar($request, null);
        $classe = DB::transaction(function () use ($dados, $request) {
            if (! empty($dados['eh_padrao'])) {
                ClassePontoVenda::where('eh_padrao', true)->update(['eh_padrao' => false]);
            }
            $ordem = (int) ClassePontoVenda::max('ordem') + 1;

            return ClassePontoVenda::create($dados + ['empresa_id' => $request->user()->empresa_id, 'ordem' => $ordem]);
        });
        // Primeira classe vira a padrão: sem padrão, loja sem classe não teria XP.
        if (! ClassePontoVenda::where('eh_padrao', true)->exists()) {
            $classe->update(['eh_padrao' => true]);
        }

        return response()->json(['classe' => $this->json($classe->loadCount('pontosVenda'))], 201);
    }

    public function update(Request $request, ClassePontoVenda $classe): JsonResponse
    {
        $dados = $this->validar($request, $classe);
        DB::transaction(function () use ($dados, $classe) {
            if (! empty($dados['eh_padrao'])) {
                ClassePontoVenda::where('eh_padrao', true)->whereKeyNot($classe->id)->update(['eh_padrao' => false]);
            }
            $classe->update($dados);
        });

        return response()->json(['classe' => $this->json($classe->refresh()->loadCount('pontosVenda'))]);
    }

    /** Desativa (nunca apaga — as OS antigas apontam pra ela). A padrão não pode sair. */
    public function destroy(ClassePontoVenda $classe): JsonResponse
    {
        abort_if($classe->eh_padrao, 422, 'Marque outra classe como padrão antes de desativar esta.');
        $classe->update(['ativo' => false]);

        return response()->json(['classe' => $this->json($classe->loadCount('pontosVenda'))]);
    }

    /** Lojas com sugestão diferente da classe atual (rotina `xp:sugerir-classes`). */
    public function sugestoes(): JsonResponse
    {
        $padrao = ClassePontoVenda::where('eh_padrao', true)->value('id');
        $lojas = PontoVenda::query()
            ->with(['classe:id,uuid,nome,icone,cor,xp_base', 'classeSugerida:id,uuid,nome,icone,cor,xp_base'])
            ->whereNotNull('classe_sugerida_id')
            ->where('ativo', true)
            ->get()
            ->filter(fn (PontoVenda $p) => $p->classe_sugerida_id !== ($p->classe_id ?? $padrao))
            ->sortBy('fantasia')
            ->values();

        return response()->json(['sugestoes' => $lojas->map(fn (PontoVenda $p) => [
            'ponto_venda' => ['id' => $p->uuid, 'fantasia' => $p->fantasia],
            'atual' => $p->classe ? $this->resumo($p->classe) : null,
            'sugerida' => $this->resumo($p->classeSugerida),
        ])->all()]);
    }

    public function aceitarSugestoes(Request $request): JsonResponse
    {
        $empresaId = $request->user()->empresa_id;
        $dados = $request->validate([
            'pontos_venda_uuids' => ['required', 'array', 'min:1', 'max:500'],
            'pontos_venda_uuids.*' => ['uuid', Rule::exists('pontos_venda', 'uuid')->where('empresa_id', $empresaId)],
        ]);
        $atualizadas = PontoVenda::whereIn('uuid', $dados['pontos_venda_uuids'])
            ->whereNotNull('classe_sugerida_id')
            ->update(['classe_id' => DB::raw('classe_sugerida_id')]);

        return response()->json(['atualizadas' => $atualizadas]);
    }

    private function validar(Request $request, ?ClassePontoVenda $classe): array
    {
        $obrigatorio = $classe ? 'sometimes' : 'required';

        return $request->validate([
            'nome' => [$obrigatorio, 'string', 'max:60'],
            'icone' => ['nullable', 'string', 'max:60'],
            'cor' => ['nullable', 'string', 'max:20'],
            'xp_base' => [$obrigatorio, 'integer', 'min:1', 'max:100000'],
            'duracao_padrao_minutos' => [$obrigatorio, 'integer', 'min:1', 'max:1440'],
            'eh_padrao' => ['sometimes', 'boolean'],
            'ordem' => ['sometimes', 'integer', 'min:0'],
            'ativo' => ['sometimes', 'boolean'],
        ]);
    }

    private function resumo(?ClassePontoVenda $c): ?array
    {
        return $c ? ['id' => $c->uuid, 'nome' => $c->nome, 'icone' => $c->icone, 'cor' => $c->cor, 'xp_base' => $c->xp_base] : null;
    }

    private function json(ClassePontoVenda $c): array
    {
        return [
            'id' => $c->uuid,
            'nome' => $c->nome,
            'icone' => $c->icone,
            'cor' => $c->cor,
            'xp_base' => $c->xp_base,
            'duracao_padrao_minutos' => $c->duracao_padrao_minutos,
            'eh_padrao' => $c->eh_padrao,
            'ordem' => $c->ordem,
            'ativo' => $c->ativo,
            'lojas' => $c->pontos_venda_count ?? null,
        ];
    }
}

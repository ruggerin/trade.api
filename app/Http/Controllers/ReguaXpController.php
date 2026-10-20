<?php

namespace App\Http\Controllers;

use App\Models\MotivoNaoExecucao;
use App\Models\PlanoAcao;
use App\Models\Supervisor;
use App\Models\XpLancamento;
use App\Models\XpOcorrenciaClassificacao;
use App\Support\Fuso;
use App\Support\Xp\PainelXp;
use App\Support\Xp\PontuacaoPromotorXp;
use App\Support\Xp\ResumoXp;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Régua da operação no admin (docs/88 §11): possível × realizado, perdas, ocorrências e a
 * classificação delas, evolução, qualidade do planejamento, valor gerado e o ranking completo.
 */
class ReguaXpController extends Controller
{
    public function regua(Request $request): JsonResponse
    {
        [$dia, $periodo, $supervisorId] = $this->filtros($request);

        return response()->json(['regua' => PainelXp::regua($request->user()->empresa, $dia, $periodo, $supervisorId)]);
    }

    public function ocorrencias(Request $request): JsonResponse
    {
        [$dia, $periodo, $supervisorId] = $this->filtros($request);
        $request->validate(['categoria' => ['nullable', 'string', 'max:40']]);
        [$inicio, $fim] = PainelXp::periodo($dia, $periodo);

        return response()->json([
            'ocorrencias' => PainelXp::ocorrencias($request->user()->empresa, $inicio, $fim, $supervisorId, $request->query('categoria'))->all(),
        ]);
    }

    /**
     * Classificação append-only (§8): justificada ou não, a causa (motivos de não execução,
     * doc 59), comentário e o plano de ação gerado. Não muda pontos — o número nunca é perdoado.
     */
    public function classificar(Request $request, XpLancamento $xpLancamento): JsonResponse
    {
        abort_unless($xpLancamento->natureza === XpLancamento::PERDA, 422, 'Só perdas podem ser classificadas.');
        $empresaId = $request->user()->empresa_id;
        $dados = $request->validate([
            'justificada' => ['required', 'boolean'],
            'causa_uuid' => ['nullable', 'uuid', Rule::exists('motivos_nao_execucao', 'uuid')->where('empresa_id', $empresaId)],
            'comentario' => ['nullable', 'string', 'max:2000'],
            'plano_acao_uuid' => ['nullable', 'uuid', Rule::exists('planos_acao', 'uuid')->where('empresa_id', $empresaId)],
        ]);

        XpOcorrenciaClassificacao::create([
            'empresa_id' => $empresaId,
            'xp_lancamento_id' => $xpLancamento->id,
            'justificada' => $dados['justificada'],
            'motivo_nao_execucao_id' => isset($dados['causa_uuid']) ? MotivoNaoExecucao::where('uuid', $dados['causa_uuid'])->value('id') : null,
            'comentario' => $dados['comentario'] ?? null,
            'plano_acao_id' => isset($dados['plano_acao_uuid']) ? PlanoAcao::where('uuid', $dados['plano_acao_uuid'])->value('id') : null,
            'criado_por_id' => $request->user()->id,
        ]);
        // Justificada protege a ofensiva (§12.3): o resumo do dia muda.
        ResumoXp::recalcular($request->user()->empresa, (int) $xpLancamento->usuario_id, $xpLancamento->competencia->toDateString());

        $ocorrencia = PainelXp::ocorrencias(
            $request->user()->empresa,
            $xpLancamento->competencia->toDateString(),
            $xpLancamento->competencia->toDateString(),
            null,
            $xpLancamento->categoria,
        )->firstWhere('id', $this->idDoGrupo($xpLancamento));

        return response()->json(['ocorrencia' => $ocorrencia], 201);
    }

    public function evolucao(Request $request): JsonResponse
    {
        [, , $supervisorId] = $this->filtros($request);

        return response()->json(['semanas' => PainelXp::evolucao($request->user()->empresa, $supervisorId)]);
    }

    public function planejamento(Request $request): JsonResponse
    {
        [$dia, , $supervisorId] = $this->filtros($request);

        return response()->json(['planejamento' => PainelXp::planejamento($request->user()->empresa, $dia, $supervisorId)]);
    }

    public function valorGerado(Request $request): JsonResponse
    {
        $request->validate(['mes' => ['nullable', 'date_format:Y-m']]);
        $mes = $request->query('mes') ?? now(Fuso::daEmpresa($request->user()->empresa))->format('Y-m');

        return response()->json(['valor_gerado' => PainelXp::valorGerado($request->user()->empresa, $mes)]);
    }

    public function ranking(Request $request): JsonResponse
    {
        [, , $supervisorId] = $this->filtros($request);

        return response()->json([
            'ranking' => PontuacaoPromotorXp::ranking(
                $request->user()->empresa,
                $request->user(),
                $request->query('semana') === 'passada' ? 'passada' : 'atual',
                completo: true,
                supervisorId: $supervisorId,
            ),
        ]);
    }

    /** @return array{0: string, 1: string, 2: ?int} dia, período, supervisor */
    private function filtros(Request $request): array
    {
        $empresa = $request->user()->empresa;
        $request->validate([
            'dia' => ['nullable', 'date_format:Y-m-d'],
            'periodo' => ['nullable', Rule::in(['dia', 'semana'])],
            'supervisor_uuid' => ['nullable', 'uuid', Rule::exists('supervisores', 'uuid')->where('empresa_id', $empresa->id)],
        ]);
        $dia = $request->query('dia') ?? now(Fuso::daEmpresa($empresa))->toDateString();
        $supervisorId = $request->filled('supervisor_uuid')
            ? Supervisor::where('uuid', $request->query('supervisor_uuid'))->value('id')
            : null;

        return [$dia, $request->query('periodo', 'dia'), $supervisorId];
    }

    /** A ocorrência é identificada pelo primeiro lançamento de perda do grupo (visita/OS × categoria). */
    private function idDoGrupo(XpLancamento $lancamento): string
    {
        return XpLancamento::withoutGlobalScopes()
            ->where('natureza', XpLancamento::PERDA)
            ->where('categoria', $lancamento->categoria)
            ->where(fn ($q) => $lancamento->visita_id
                ? $q->where('visita_id', $lancamento->visita_id)
                : $q->whereNull('visita_id')->where('ordem_servico_id', $lancamento->ordem_servico_id))
            ->orderBy('id')
            ->value('uuid');
    }
}

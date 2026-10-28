<?php

namespace App\Http\Controllers;

use App\Models\Integracao;
use App\Models\IntegracaoWebhook;
use App\Models\IntegracaoWebhookEntrega;
use App\Support\Webhooks;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Webhooks de uma integração na tela do admin (docs/96 Fase 4) — só ADMIN, como o resto da tela.
 * O segredo da assinatura só sai em texto ao criar e ao gerar um novo.
 *
 * O webhook não é tenant-aware por conta própria: cada ação confere que ele é da integração da rota
 * (que é).
 */
class IntegracaoWebhookController extends Controller
{
    public const MAX_POR_INTEGRACAO = 5;

    public function index(Integracao $integracao): JsonResponse
    {
        $webhooks = $integracao->webhooks()->orderBy('id')
            ->withCount(['entregas as pendentes' => fn ($q) => $q->whereNull('entregue_em')->whereNull('desistiu_em')])
            ->get();

        return response()->json([
            'webhooks' => $webhooks->map(fn (IntegracaoWebhook $w) => $this->formatar($w))->values(),
            'eventos_permitidos' => Webhooks::eventosPermitidos($integracao),
        ]);
    }

    public function store(Request $request, Integracao $integracao): JsonResponse
    {
        $this->abortarSeRevogada($integracao);
        abort_if($integracao->webhooks()->count() >= self::MAX_POR_INTEGRACAO, 422, 'Máximo de '.self::MAX_POR_INTEGRACAO.' webhooks por integração.');
        $dados = $request->validate($this->regras(parcial: false));

        $segredo = Webhooks::novoSegredo();
        $webhook = IntegracaoWebhook::create([
            'integracao_id' => $integracao->id,
            'url' => $dados['url'],
            'eventos' => array_values(array_unique($dados['eventos'])),
            'segredo' => $segredo,
        ]);

        return response()->json(['webhook' => $this->formatar($webhook->fresh()), 'segredo' => $segredo], 201);
    }

    public function update(Request $request, Integracao $integracao, IntegracaoWebhook $webhook): JsonResponse
    {
        $this->daIntegracao($integracao, $webhook);
        $this->abortarSeRevogada($integracao);
        $dados = $request->validate($this->regras(parcial: true) + ['ativo' => ['sometimes', 'boolean']]);

        if (isset($dados['eventos'])) {
            $dados['eventos'] = array_values(array_unique($dados['eventos']));
        }
        $webhook->fill($dados);
        // Religar zera a contagem de falhas e tira o aviso de desligado.
        if (($dados['ativo'] ?? false) === true) {
            $webhook->forceFill(['falhas_seguidas' => 0, 'desativado_em' => null, 'desativado_motivo' => null]);
        }
        $webhook->save();

        return response()->json(['webhook' => $this->formatar($webhook)]);
    }

    public function destroy(Integracao $integracao, IntegracaoWebhook $webhook): JsonResponse
    {
        $this->daIntegracao($integracao, $webhook);
        $webhook->delete();

        return response()->json(status: 204);
    }

    public function novoSegredo(Integracao $integracao, IntegracaoWebhook $webhook): JsonResponse
    {
        $this->daIntegracao($integracao, $webhook);
        $this->abortarSeRevogada($integracao);
        $segredo = Webhooks::novoSegredo();
        $webhook->update(['segredo' => $segredo]);

        return response()->json(['webhook' => $this->formatar($webhook), 'segredo' => $segredo]);
    }

    /** Manda um aviso `teste` na hora e devolve o que o sistema do cliente respondeu. */
    public function testar(Integracao $integracao, IntegracaoWebhook $webhook): JsonResponse
    {
        $this->daIntegracao($integracao, $webhook);
        $entrega = $webhook->entregas()->create([
            'evento' => Webhooks::EVENTO_TESTE,
            'payload' => ['event' => Webhooks::EVENTO_TESTE, 'created_at' => now()->utc()->toIso8601ZuluString(), 'data' => ['integracao' => $integracao->nome]],
            'proxima_tentativa_em' => now(),
        ]);
        $ok = Webhooks::entregar($entrega->setRelation('webhook', $webhook));

        return response()->json(['ok' => $ok, 'entrega' => $this->formatarEntrega($entrega->fresh())]);
    }

    public function entregas(Request $request, Integracao $integracao, IntegracaoWebhook $webhook): JsonResponse
    {
        $this->daIntegracao($integracao, $webhook);
        $entregas = $webhook->entregas()
            ->when($request->boolean('so_falhas'), fn ($q) => $q->whereNull('entregue_em')->whereNotNull('ultimo_erro'))
            ->orderByDesc('id')
            ->limit(min((int) $request->integer('limite', 50), 200))
            ->get();

        return response()->json(['entregas' => $entregas->map(fn ($e) => $this->formatarEntrega($e))->values()]);
    }

    /** Põe de novo na fila um aviso que falhou ou foi abandonado. */
    public function reenviar(Integracao $integracao, IntegracaoWebhook $webhook, IntegracaoWebhookEntrega $entrega): JsonResponse
    {
        $this->daIntegracao($integracao, $webhook);
        abort_if($entrega->webhook_id !== $webhook->id, 404);
        $entrega->forceFill(['entregue_em' => null, 'desistiu_em' => null, 'tentativas' => 0, 'proxima_tentativa_em' => now()])->save();

        return response()->json(['entrega' => $this->formatarEntrega($entrega)]);
    }

    private function regras(bool $parcial): array
    {
        $obrigatorio = $parcial ? 'sometimes' : 'required';

        return [
            'url' => [$obrigatorio, 'string', 'max:2048', function (string $atributo, mixed $valor, Closure $falhar): void {
                if (($motivo = Webhooks::enderecoRecusado((string) $valor)) !== null) {
                    $falhar($motivo);
                }
            }],
            'eventos' => [$obrigatorio, 'array', 'min:1'],
            'eventos.*' => ['string', Rule::in(array_keys(Webhooks::EVENTOS))],
        ];
    }

    private function daIntegracao(Integracao $integracao, IntegracaoWebhook $webhook): void
    {
        abort_if($webhook->integracao_id !== $integracao->id, 404);
    }

    private function abortarSeRevogada(Integracao $integracao): void
    {
        abort_if($integracao->revogada(), 409, 'Esta integração foi revogada.');
    }

    private function formatar(IntegracaoWebhook $w): array
    {
        return [
            'uuid' => $w->uuid,
            'url' => $w->url,
            'eventos' => $w->eventos,
            'ativo' => $w->ativo,
            'falhas_seguidas' => $w->falhas_seguidas,
            'desativado_em' => $w->desativado_em?->toIso8601String(),
            'desativado_motivo' => $w->desativado_motivo,
            'ultima_entrega_em' => $w->ultima_entrega_em?->toIso8601String(),
            'pendentes' => $w->pendentes ?? null,
            'created_at' => $w->created_at?->toIso8601String(),
        ];
    }

    private function formatarEntrega(IntegracaoWebhookEntrega $e): array
    {
        return [
            'uuid' => $e->uuid,
            'evento' => $e->evento,
            'situacao' => match (true) {
                $e->entregue_em !== null => 'entregue',
                $e->desistiu_em !== null => 'abandonada',
                $e->tentativas > 0 => 'tentando',
                default => 'na_fila',
            },
            'tentativas' => $e->tentativas,
            'ultimo_status' => $e->ultimo_status,
            'ultimo_erro' => $e->ultimo_erro,
            'ultima_duracao_ms' => $e->ultima_duracao_ms,
            'proxima_tentativa_em' => $e->entregue_em || $e->desistiu_em ? null : $e->proxima_tentativa_em?->toIso8601String(),
            'entregue_em' => $e->entregue_em?->toIso8601String(),
            'created_at' => $e->created_at?->toIso8601String(),
        ];
    }
}

<?php

namespace App\Support;

use App\Enums\EscopoIntegracao;
use App\Models\Integracao;
use App\Models\IntegracaoWebhook;
use App\Models\IntegracaoWebhookEntrega;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Webhooks da API de integração (docs/96 Fase 4): o Horus avisa o sistema do cliente quando algo
 * acontece, em vez de o cliente ficar perguntando.
 *
 * - Cada evento exige a mesma permissão da leitura correspondente: sem `rupturas:ler`, o webhook não
 *   recebe `ruptura.registrada`, mesmo marcado.
 * - O aviso entra numa fila (tabela) na mesma transação do fato, e o `integracoes:entregar-webhooks`
 *   (cron, a cada minuto) entrega. A API não tem worker de fila, e um cliente lento ou fora do ar não
 *   pode atrasar o check-out do promotor.
 * - Assinatura: `X-Horus-Signature: t=<unix>,v1=<hex>`, com v1 = HMAC-SHA256(segredo, "<t>.<corpo>").
 *   O cliente confere e recusa aviso com `t` velho (repetição). `X-Horus-Delivery` é o id do aviso:
 *   pode chegar mais de uma vez (nova tentativa), então o cliente ignora id repetido.
 * - Novas tentativas: 1, 5, 15, 60, 240 e 720 minutos. Depois disso o aviso é abandonado. Com
 *   FALHAS_PARA_DESATIVAR falhas seguidas o webhook é desligado e a tela avisa.
 * - Só endereço público: nada de rede interna, localhost ou metadados da nuvem (SSRF), conferido
 *   ao salvar e de novo a cada entrega (o DNS pode mudar).
 */
class Webhooks
{
    /** evento => permissão exigida. */
    public const EVENTOS = [
        'visita.finalizada' => EscopoIntegracao::VISITAS_LER,
        'visita.cancelada' => EscopoIntegracao::VISITAS_LER,
        'registro.criado' => EscopoIntegracao::REGISTROS_LER,
        'ruptura.registrada' => EscopoIntegracao::RUPTURAS_LER,
        'ruptura.resolvida' => EscopoIntegracao::RUPTURAS_LER,
    ];

    public const EVENTO_TESTE = 'teste';

    public const ESPERAS_MINUTOS = [1, 5, 15, 60, 240, 720];

    public const FALHAS_PARA_DESATIVAR = 20;

    public const TIMEOUT_SEGUNDOS = 10;

    public const DIAS_RETENCAO = 30;

    /** Troca a resolução de DNS nos testes. @var (callable(string): list<string>)|null */
    public static $resolver = null;

    public static function novoSegredo(): string
    {
        return 'whsec_'.Str::random(40);
    }

    /**
     * Põe o aviso na fila de cada webhook da empresa que quer este evento e tem a permissão.
     * `$dados` pode ser uma função: só é montado se houver quem receba (o check-out de quem não usa
     * webhook não paga as consultas do aviso).
     *
     * @param  array<string, mixed>|callable(): array<string, mixed>  $dados
     */
    public static function disparar(int $empresaId, string $evento, array|callable $dados): void
    {
        try {
            if (! self::empresaTemWebhook($empresaId)) {
                return;
            }
            $webhooks = IntegracaoWebhook::query()
                ->where('ativo', true)
                ->whereHas('integracao', fn ($q) => $q->withoutGlobalScopes()->where('empresa_id', $empresaId)->where('ativa', true)->whereNull('revogada_em'))
                ->with(['integracao' => fn ($q) => $q->withoutGlobalScopes()])
                ->get()
                ->filter(fn (IntegracaoWebhook $w) => in_array($evento, $w->eventos ?? [], true) && $w->integracao->temEscopo(self::EVENTOS[$evento]));

            if ($webhooks->isEmpty()) {
                return;
            }
            $dados = is_callable($dados) ? $dados() : $dados;
            foreach ($webhooks as $webhook) {
                $webhook->entregas()->create([
                    'evento' => $evento,
                    'payload' => ['event' => $evento, 'created_at' => now()->utc()->toIso8601ZuluString(), 'data' => $dados],
                    'proxima_tentativa_em' => now(),
                ]);
            }
        } catch (\Throwable $e) {
            // Aviso nunca derruba a operação de verdade (check-out, registro).
            report($e);
        }
    }

    /** Uma consulta barata antes de qualquer outra: quase toda empresa não tem webhook. */
    public static function empresaTemWebhook(int $empresaId): bool
    {
        return DB::table('integracao_webhooks as w')
            ->join('integracoes as i', 'i.id', '=', 'w.integracao_id')
            ->where('i.empresa_id', $empresaId)
            ->where('w.ativo', true)
            ->where('i.ativa', true)
            ->whereNull('i.revogada_em')
            ->exists();
    }

    /** Entrega o que está vencido na fila. @return array{entregues: int, falhas: int} */
    public static function entregarPendentes(int $limite = 200): array
    {
        $contagem = ['entregues' => 0, 'falhas' => 0];
        $pendentes = IntegracaoWebhookEntrega::query()
            ->whereNull('entregue_em')
            ->whereNull('desistiu_em')
            ->where('proxima_tentativa_em', '<=', now())
            ->whereHas('webhook', fn ($q) => $q->where('ativo', true))
            ->with('webhook')
            ->orderBy('id')
            ->limit($limite)
            ->get();

        foreach ($pendentes as $entrega) {
            self::entregar($entrega) ? $contagem['entregues']++ : $contagem['falhas']++;
        }

        return $contagem;
    }

    public static function entregar(IntegracaoWebhookEntrega $entrega): bool
    {
        $webhook = $entrega->webhook;
        $corpo = json_encode($entrega->payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $instante = now()->getTimestamp();
        $inicio = microtime(true);
        $status = null;
        $erro = null;

        if (($motivo = self::enderecoRecusado($webhook->url)) !== null) {
            $erro = $motivo;
        } else {
            try {
                $resposta = Http::timeout(self::TIMEOUT_SEGUNDOS)
                    ->withoutRedirecting()
                    ->withHeaders([
                        'Content-Type' => 'application/json',
                        'User-Agent' => 'Horus-Webhooks/1',
                        'X-Horus-Event' => $entrega->evento,
                        'X-Horus-Delivery' => $entrega->uuid,
                        'X-Horus-Signature' => 't='.$instante.',v1='.self::assinar($webhook->segredo, $instante, $corpo),
                    ])
                    ->withBody($corpo, 'application/json')
                    ->post($webhook->url);
                $status = $resposta->status();
                if (! $resposta->successful()) {
                    $erro = "Resposta {$status}: ".mb_substr(trim($resposta->body()), 0, 300);
                }
            } catch (\Throwable $e) {
                $erro = mb_substr($e->getMessage(), 0, 500);
            }
        }

        $duracao = (int) round((microtime(true) - $inicio) * 1000);
        $tentativas = $entrega->tentativas + 1;

        DB::transaction(function () use ($entrega, $webhook, $status, $erro, $duracao, $tentativas) {
            if ($erro === null) {
                $entrega->forceFill(['tentativas' => $tentativas, 'entregue_em' => now(), 'ultimo_status' => $status, 'ultimo_erro' => null, 'ultima_duracao_ms' => $duracao])->save();
                $webhook->forceFill(['falhas_seguidas' => 0, 'ultima_entrega_em' => now()])->save();

                return;
            }

            $espera = self::ESPERAS_MINUTOS[$tentativas - 1] ?? null;
            $entrega->forceFill([
                'tentativas' => $tentativas,
                'ultimo_status' => $status,
                'ultimo_erro' => $erro,
                'ultima_duracao_ms' => $duracao,
                'proxima_tentativa_em' => $espera !== null ? now()->addMinutes($espera) : null,
                'desistiu_em' => $espera === null ? now() : null,
            ])->save();

            $falhas = $webhook->falhas_seguidas + 1;
            $webhook->forceFill(['falhas_seguidas' => $falhas] + ($falhas >= self::FALHAS_PARA_DESATIVAR ? [
                'ativo' => false,
                'desativado_em' => now(),
                'desativado_motivo' => "Desligado depois de {$falhas} falhas seguidas. Último erro: ".mb_substr($erro, 0, 150),
            ] : []))->save();
        });

        return $erro === null;
    }

    public static function assinar(string $segredo, int $instante, string $corpo): string
    {
        return hash_hmac('sha256', $instante.'.'.$corpo, $segredo);
    }

    /** Motivo pra recusar o endereço, ou null se é público. */
    public static function enderecoRecusado(string $url): ?string
    {
        $partes = parse_url($url);
        $esquema = strtolower($partes['scheme'] ?? '');
        $host = $partes['host'] ?? null;
        if (! $host || ! in_array($esquema, app()->isProduction() ? ['https'] : ['https', 'http'], true)) {
            return app()->isProduction() ? 'O endereço precisa ser https://.' : 'O endereço precisa ser http:// ou https://.';
        }
        if (isset($partes['user']) || isset($partes['pass'])) {
            return 'O endereço não pode ter usuário e senha.';
        }

        $host = trim($host, '[]');
        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (self::$resolver ? (self::$resolver)($host) : (gethostbynamel($host) ?: []));
        if ($ips === []) {
            return "Não foi possível encontrar o endereço {$host}.";
        }
        foreach ($ips as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                return 'O endereço aponta para uma rede interna; use um endereço público.';
            }
        }

        return null;
    }

    /** O webhook recebe este evento? (marcado e com a permissão). */
    public static function eventosPermitidos(Integracao $integracao): array
    {
        return array_keys(array_filter(self::EVENTOS, fn (EscopoIntegracao $e) => $integracao->temEscopo($e)));
    }

    // ── Conteúdo dos avisos (o mesmo formato da leitura, com os códigos do ERP) ────────────────

    public static function dadosVisita(\App\Models\Visita $v): array
    {
        $v->loadMissing(['pontoVenda:id,uuid,codigo_externo', 'usuario:id,uuid,codigo_externo', 'ordemServico:id,uuid']);

        return [
            'uuid' => $v->uuid,
            'loja_uuid' => $v->pontoVenda?->uuid,
            'loja_codigo_externo' => $v->pontoVenda?->codigo_externo,
            'promotor_uuid' => $v->usuario?->uuid,
            'promotor_codigo_externo' => $v->usuario?->codigo_externo,
            'ordem_servico_uuid' => $v->ordemServico?->uuid,
            'status' => $v->status?->value,
            'inicio' => $v->inicio_data?->copy()->utc()->toIso8601ZuluString(),
            'fim' => $v->fim_data?->copy()->utc()->toIso8601ZuluString(),
        ];
    }

    public static function dadosRegistro(\App\Models\VisitaRegistro $r): array
    {
        $r->loadMissing(['visita:id,uuid,empresa_id,ponto_venda_id,usuario_id', 'visita.pontoVenda:id,uuid,codigo_externo', 'visita.usuario:id,uuid,codigo_externo', 'produtoAuditoria:id,uuid,codigo_externo,descricao', 'tipoRegistro:id,descricao']);

        return [
            'uuid' => $r->uuid,
            'visita_uuid' => $r->visita?->uuid,
            'loja_codigo_externo' => $r->visita?->pontoVenda?->codigo_externo,
            'promotor_codigo_externo' => $r->visita?->usuario?->codigo_externo,
            'tipo_registro' => $r->tipoRegistro?->descricao,
            'produto_codigo_externo' => $r->produtoAuditoria?->codigo_externo,
            'produto' => $r->produtoAuditoria?->descricao,
            'ruptura' => $r->ruptura,
            'observacao' => $r->observacao,
            'criado_em' => $r->created_at?->copy()->utc()->toIso8601ZuluString(),
            'resolvida_em' => $r->alerta_resolvido_em?->copy()->utc()->toIso8601ZuluString(),
        ];
    }
}

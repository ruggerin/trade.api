<?php

namespace App\Http\Middleware;

use App\Models\Integracao;
use App\Support\ProblemaIntegracao;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Response;

/**
 * Portaria de /api/integracao/v1 (docs/96 §6.2), depois do auth:sanctum. O token já chega válido,
 * não revogado, não vencido e do canal certo (SessaoApp::tokenAceito); aqui confere a integração
 * (pausada? empresa bloqueada? IP permitido?), aplica o limite por minuto e grava a auditoria de
 * toda chamada, inclusive as recusadas, pro suporte ajudar o cliente.
 *
 * O escopo de cada rota é conferido depois, pelo EnsureEscopoIntegracao.
 */
class EnsureIntegracao
{
    /** Atributo do request onde o controller diz quantos itens leu (auditoria). */
    public const ATRIBUTO_REGISTROS = 'integracao_registros';

    public function handle(Request $request, Closure $next): Response
    {
        $integracao = $request->user();
        if (! $integracao instanceof Integracao) {
            return ProblemaIntegracao::resposta(401, 'Use um token de integração criado na tela Integrações do admin.');
        }

        $inicio = microtime(true);
        $response = $this->barrar($request, $integracao) ?? $next($request);

        if ($response->getStatusCode() < 400 && $response->headers->has('X-RateLimit-Limit') === false) {
            $this->limiteNosHeaders($response, $integracao);
        }

        $this->auditar($request, $integracao, $response, $inicio);

        return $response;
    }

    private function barrar(Request $request, Integracao $integracao): ?Response
    {
        if (! $integracao->ativa) {
            return ProblemaIntegracao::resposta(403, 'Esta integração está pausada. Um administrador pode reativá-la na tela Integrações.');
        }

        if (! $integracao->empresa?->ativo) {
            return ProblemaIntegracao::resposta(403, 'A empresa desta integração está bloqueada. Fale com o suporte.');
        }

        $ips = $integracao->ips_permitidos ?? [];
        if ($ips !== [] && ! IpUtils::checkIp((string) $request->ip(), $ips)) {
            return ProblemaIntegracao::resposta(403, "O IP {$request->ip()} não está na lista de IPs permitidos desta integração.");
        }

        $chave = $this->chaveLimite($integracao);
        if (RateLimiter::tooManyAttempts($chave, $integracao->limite_por_minuto)) {
            $espera = RateLimiter::availableIn($chave);

            return ProblemaIntegracao::resposta(429, "Limite de {$integracao->limite_por_minuto} requisições por minuto atingido. Tente de novo em {$espera} segundos.", null, [
                'Retry-After' => $espera,
                'X-RateLimit-Limit' => $integracao->limite_por_minuto,
                'X-RateLimit-Remaining' => 0,
            ]);
        }
        RateLimiter::hit($chave, 60);

        return null;
    }

    private function limiteNosHeaders(Response $response, Integracao $integracao): void
    {
        $response->headers->set('X-RateLimit-Limit', (string) $integracao->limite_por_minuto);
        $response->headers->set('X-RateLimit-Remaining', (string) RateLimiter::remaining($this->chaveLimite($integracao), $integracao->limite_por_minuto));
    }

    private function chaveLimite(Integracao $integracao): string
    {
        return 'integracao:'.$integracao->id;
    }

    private function auditar(Request $request, Integracao $integracao, Response $response, float $inicio): void
    {
        try {
            $status = $response->getStatusCode();
            $erro = null;
            if ($status >= 400) {
                $corpo = json_decode((string) $response->getContent(), true);
                $erro = mb_substr((string) ($corpo['detail'] ?? $corpo['message'] ?? ''), 0, 1000) ?: null;
            }

            $integracao->requisicoes()->create([
                'metodo' => $request->method(),
                // Só o caminho, sem a query string: nada de token ou dado do cliente na auditoria.
                'rota' => mb_substr('/'.$request->path(), 0, 255),
                'status' => $status,
                'ip' => $request->ip(),
                'duracao_ms' => (int) round((microtime(true) - $inicio) * 1000),
                'registros' => $request->attributes->get(self::ATRIBUTO_REGISTROS),
                'erro' => $erro,
            ]);

            // No máximo uma escrita por minuto na integração (docs/96 §4.1).
            if ($integracao->ultimo_uso_em === null || $integracao->ultimo_uso_em->lt(now()->subMinute()) || $integracao->ultimo_ip !== $request->ip()) {
                $integracao->forceFill(['ultimo_uso_em' => now(), 'ultimo_ip' => $request->ip()])->saveQuietly();
            }
        } catch (\Throwable $e) {
            // Auditoria nunca derruba a resposta de verdade.
            report($e);
        }
    }
}

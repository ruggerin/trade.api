<?php

namespace App\Http\Controllers;

use App\Support\Saude\VerificacaoSaude;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/saude — veredito de saúde do servidor pro Uptime Kuma (docs/71).
 *
 * - 200 `ok`      tudo certo.
 * - 200 `alerta`  funciona, mas algo merece olhar (disco apertado, fila parada, cron sem batimento…).
 *                 Com `?estrito=1` o alerta também vira 503 — pra o monitor tratar alerta como queda.
 * - 503 `falha`   o servidor não está em condição de atender (banco, storage, memória crítica…).
 *
 * Público, mas só o veredito; o detalhe de cada verificação exige o token (SAUDE_TOKEN).
 */
class SaudeController extends Controller
{
    public function __invoke(Request $request, VerificacaoSaude $saude): JsonResponse
    {
        $resultado = $saude->executar();
        $status = $resultado['status'];

        $http = match (true) {
            $status === VerificacaoSaude::FALHA => 503,
            $status === VerificacaoSaude::ALERTA && $request->boolean('estrito') => 503,
            default => 200,
        };

        $corpo = ['status' => $status, 'verificado_em' => now()->toIso8601String()];

        if ($this->autorizadoParaDetalhe($request)) {
            $corpo['app'] = [
                'ambiente' => config('app.env'),
                'versao' => config('sentry.release') ?: null,
                'php' => PHP_VERSION,
            ];
            $corpo['verificacoes'] = $resultado['verificacoes'];
        }

        return response()->json($corpo, $http)->header('Cache-Control', 'no-store, max-age=0');
    }

    /** Sem SAUDE_TOKEN configurado o detalhe nunca sai; comparação em tempo constante. */
    private function autorizadoParaDetalhe(Request $request): bool
    {
        $esperado = (string) config('saude.token');
        $enviado = (string) ($request->header('X-Saude-Token') ?? '');

        return $esperado !== '' && $enviado !== '' && hash_equals($esperado, $enviado);
    }
}

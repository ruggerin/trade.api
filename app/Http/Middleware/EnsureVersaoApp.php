<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Versão mínima do app (docs/67 §5.2, Fase 2): versão antiga não tem as travas novas, então é
 * recusada com 426 e o app mostra "Atualize o app". Só olha requisições do app (X-Client: mobile);
 * o admin web não é afetado. Configurado em config/seguranca.php (APP_VERSAO_MINIMA).
 */
class EnsureVersaoApp
{
    public function handle(Request $request, Closure $next): Response
    {
        $minima = (string) config('seguranca.versao_minima_app');

        if ($minima !== '' && strtolower((string) $request->header('X-Client')) === 'mobile') {
            $versao = (string) ($request->header('X-App-Version') ?: '0.0.0');

            if (version_compare($versao, $minima, '<')) {
                return response()->json([
                    'message' => 'Esta versão do app não é mais aceita. Atualize o Horus para continuar.',
                    'codigo' => 'APP_DESATUALIZADO',
                    'versao_minima' => $minima,
                ], 426);
            }
        }

        return $next($request);
    }
}

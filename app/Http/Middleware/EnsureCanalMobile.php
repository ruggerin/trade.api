<?php

namespace App\Http\Middleware;

use App\Models\PersonalAccessToken;
use App\Support\SessaoApp;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rotas exclusivas do app (check-in, registros, rastreamento) — docs/67 §4.1. Bloqueia só token
 * `web` (admin): o admin web não tem trava de aparelho, então sem isso bastava logar pelo navegador
 * e usar o token num celular qualquer. Token legado (canal null) passa, pra não derrubar ninguém
 * no deploy; some no próximo login.
 */
class EnsureCanalMobile
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->user()?->currentAccessToken();

        if ($token instanceof PersonalAccessToken && $token->canal === SessaoApp::CANAL_WEB) {
            abort(403, 'Esta ação só pode ser feita pelo app.');
        }

        return $next($request);
    }
}

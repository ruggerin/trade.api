<?php

namespace App\Http\Middleware;

use App\Support\Xp\ParametrosXp;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rotas da régua de XP (docs/88 §2.1): só pra empresa com o recurso de plano ligado
 * (`empresas.controle_xp_habilitado`, liberado pelo SUPERADMIN).
 */
class EnsureControleXp
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! ParametrosXp::habilitado($request->user()?->empresa)) {
            abort(403, 'A régua de XP não está habilitada no plano desta empresa.');
        }

        return $next($request);
    }
}

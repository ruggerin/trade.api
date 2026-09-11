<?php

namespace App\Http\Middleware;

use App\Enums\UserType;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Autorização por user_type — ver docs/02-API-BACKEND.md#autenticação: uso:
 * middleware('user_type:ADMIN,GESTOR').
 */
class EnsureUserType
{
    public function handle(Request $request, Closure $next, string ...$tipos): Response
    {
        $permitidos = array_map(fn (string $t) => UserType::from($t), $tipos);

        if (! in_array($request->user()?->user_type, $permitidos, true)) {
            abort(403, 'Você não tem permissão para executar esta ação.');
        }

        return $next($request);
    }
}

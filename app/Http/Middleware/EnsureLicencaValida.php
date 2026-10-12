<?php

namespace App\Http\Middleware;

use App\Models\Dispositivo;
use App\Models\PersonalAccessToken;
use App\Support\SessaoApp;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Licença checada em todo request, não só no login (docs/67 §5.1): usuário desativado, empresa
 * bloqueada ou aparelho desvinculado derrubam a sessão já no request seguinte, com 401 e o motivo
 * (o app e o admin voltam pro login). Antes, o token seguia valendo até a pessoa relogar.
 */
class EnsureLicencaValida
{
    public function handle(Request $request, Closure $next): Response
    {
        $usuario = $request->user();
        if (! $usuario) {
            return $next($request);
        }

        $token = $usuario->currentAccessToken();
        $motivo = match (true) {
            ! $usuario->ativo => 'USUARIO_INATIVO',
            $usuario->empresa_id !== null && ! $usuario->empresa?->ativo => 'EMPRESA_BLOQUEADA',
            // Licença do app removida (docs/67 §3): só a sessão do app cai; a do admin web fica.
            $token instanceof PersonalAccessToken && $token->canal === SessaoApp::CANAL_MOBILE && ! $usuario->licenca_mobile => SessaoApp::MOTIVO_SEM_LICENCA,
            $token instanceof PersonalAccessToken && ! $this->aparelhoValido($token, $usuario->id) => SessaoApp::MOTIVO_APARELHO_DESVINCULADO,
            default => null,
        };

        if ($motivo === null) {
            return $next($request);
        }

        if ($token instanceof PersonalAccessToken) {
            $token->forceFill(['revogado_em' => now(), 'revogado_motivo' => $motivo])->save();
        }

        return response()->json([
            'message' => match ($motivo) {
                'USUARIO_INATIVO' => 'Este usuário está desativado.',
                'EMPRESA_BLOQUEADA' => 'A empresa deste usuário está bloqueada. Fale com o suporte.',
                default => SessaoApp::mensagem($motivo, null),
            },
            'motivo' => $motivo,
        ], 401);
    }

    /**
     * Token do app só vale enquanto o aparelho dele estiver vinculado. Token mobile sem aparelho
     * (emitido antes da Fase 2) exige só que o usuário tenha algum aparelho ativo.
     */
    private function aparelhoValido(PersonalAccessToken $token, int $usuarioId): bool
    {
        if ($token->canal !== SessaoApp::CANAL_MOBILE) {
            return true;
        }

        return $token->dispositivo_id !== null
            ? Dispositivo::whereKey($token->dispositivo_id)->whereNull('desvinculado_em')->exists()
            : Dispositivo::where('usuario_id', $usuarioId)->whereNull('desvinculado_em')->exists();
    }
}

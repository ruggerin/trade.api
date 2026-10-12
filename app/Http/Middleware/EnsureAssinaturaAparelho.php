<?php

namespace App\Http\Middleware;

use App\Models\Dispositivo;
use App\Models\PersonalAccessToken;
use App\Support\AtestacaoAparelho;
use App\Support\Seguranca;
use App\Support\SessaoApp;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Prova de posse da chave do aparelho em cada request (docs/67 Fase 3, estilo DPoP): quando o
 * aparelho do token tem chave pública registrada, o request precisa vir com
 *
 *   X-Assinatura-Em: unix timestamp (segundos)
 *   X-Assinatura:    ECDSA P-256/SHA-256 (DER, base64) de "MÉTODO\n/caminho\ntimestamp\nhash-do-token"
 *
 * onde hash-do-token é o SHA-256 hex da parte secreta do token (o mesmo valor que o Sanctum guarda
 * em personal_access_tokens.token). Não assina o corpo (upload de foto, multipart), como o DPoP.
 * Token copiado pra outro aparelho ou script não passa sem a chave privada, que nunca sai do
 * celular. Aparelho sem chave (app antigo, Expo Go antes da Fase 3) não é exigido.
 */
class EnsureAssinaturaAparelho
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->user()?->currentAccessToken();

        if (! $token instanceof PersonalAccessToken || $token->canal !== SessaoApp::CANAL_MOBILE || ! $token->dispositivo_id) {
            return $next($request);
        }

        $dispositivo = Dispositivo::find($token->dispositivo_id);
        $pem = AtestacaoAparelho::pem($dispositivo?->chave_publica);
        if ($pem === null) {
            return $next($request);
        }

        $em = (string) $request->header('X-Assinatura-Em');
        $assinatura = base64_decode((string) $request->header('X-Assinatura'), true);
        $tolerancia = (int) config('seguranca.assinatura_tolerancia_segundos');
        $mensagem = $request->method()."\n/".ltrim($request->path(), '/')."\n{$em}\n{$token->token}";

        $valida = ctype_digit($em)
            && abs(time() - (int) $em) <= $tolerancia
            && $assinatura !== false
            && openssl_verify($mensagem, $assinatura, $pem, OPENSSL_ALGO_SHA256) === 1;

        if ($valida) {
            return $next($request);
        }

        if (! Seguranca::registradoRecentemente(Seguranca::ASSINATURA_INVALIDA, $request->user(), 10)) {
            Seguranca::registrar(Seguranca::ASSINATURA_INVALIDA, $request->user(), $dispositivo, [
                'rota' => $request->method().' /'.$request->path(),
                'motivo' => $em === '' ? 'sem assinatura' : (ctype_digit($em) && abs(time() - (int) $em) > $tolerancia ? 'relógio' : 'assinatura'),
            ]);
        }

        return response()->json([
            'message' => 'Não foi possível confirmar este aparelho. Entre de novo.',
            'motivo' => 'ASSINATURA_INVALIDA',
        ], 401);
    }
}

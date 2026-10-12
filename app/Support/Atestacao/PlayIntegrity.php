<?php

namespace App\Support\Atestacao;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Verificação do Google Play Integrity (docs/67 Fase 3), requisição "standard": o app pede o token
 * com requestHash = SHA256(desafio|chave pública) e o servidor decodifica pela API do Google com
 * uma conta de serviço (config/seguranca.php → play_integrity).
 *
 * Aprova só com: requestHash igual, emitido há menos de 10 min, pacote do Horus, app reconhecido
 * pela Play (PLAY_RECOGNIZED), certificado de assinatura esperado (se configurado) e aparelho
 * íntegro (MEETS_DEVICE_INTEGRITY).
 *
 * Importante: PLAY_RECOGNIZED exige que o app tenha sido instalado pela Play Store (produção ou
 * teste interno). APK distribuído direto (EAS "internal") recebe UNRECOGNIZED_VERSION e falha.
 */
final class PlayIntegrity
{
    private const ESCOPO = 'https://www.googleapis.com/auth/playintegrity';

    public static function verificar(string $token, string $requestHash): Veredito
    {
        $config = config('seguranca.play_integrity');
        if (! is_readable((string) $config['credenciais'])) {
            return Veredito::indisponivel('Play Integrity não configurado no servidor.');
        }

        try {
            $acesso = self::tokenDeAcesso($config['credenciais']);
            $resposta = Http::withToken($acesso)
                ->timeout(10)
                ->post("https://playintegrity.googleapis.com/v1/{$config['pacote']}:decodeIntegrityToken", [
                    'integrity_token' => $token,
                ]);
        } catch (ConnectionException|RequestException $e) {
            // Google fora do ar ou OAuth da conta de serviço falhou: nunca bloqueia (docs/67 §6.2).
            report($e);

            return Veredito::indisponivel('Play Integrity fora do ar: '.$e->getMessage());
        }

        if ($resposta->serverError() || $resposta->status() === 429) {
            return Veredito::indisponivel("Play Integrity respondeu {$resposta->status()}.");
        }
        if ($resposta->failed()) {
            return Veredito::falhou("Token de integridade recusado pelo Google ({$resposta->status()}).");
        }

        return self::avaliar((array) $resposta->json('tokenPayloadExternal'), $requestHash, $config);
    }

    /** Separado da chamada HTTP pra ser testável com um payload pronto. */
    public static function avaliar(array $payload, string $requestHash, array $config): Veredito
    {
        $pedido = $payload['requestDetails'] ?? [];
        $app = $payload['appIntegrity'] ?? [];
        $aparelho = $payload['deviceIntegrity']['deviceRecognitionVerdict'] ?? [];

        if (! hash_equals($requestHash, (string) ($pedido['requestHash'] ?? ''))) {
            return Veredito::falhou('requestHash não confere com o desafio.');
        }
        if (abs(now()->getTimestampMs() - (int) ($pedido['timestampMillis'] ?? 0)) > 10 * 60 * 1000) {
            return Veredito::falhou('Token de integridade antigo.');
        }
        if (($app['packageName'] ?? null) !== $config['pacote']) {
            return Veredito::falhou('Pacote diferente do Horus.');
        }
        if (($app['appRecognitionVerdict'] ?? null) !== 'PLAY_RECOGNIZED') {
            return Veredito::falhou('App não reconhecido pela Play: '.($app['appRecognitionVerdict'] ?? 'sem veredito'));
        }
        if (! empty($config['certificado_sha256']) && ! self::certificadoConfere($app['certificateSha256Digest'] ?? [], $config['certificado_sha256'])) {
            return Veredito::falhou('Certificado de assinatura diferente do oficial.');
        }
        if (! in_array('MEETS_DEVICE_INTEGRITY', $aparelho, true)) {
            return Veredito::falhou('Aparelho sem integridade (root, emulador ou sistema alterado).');
        }

        return Veredito::ok(['plataforma' => 'android', 'aparelho' => $aparelho]);
    }

    /** O Google manda o digest em base64 URL-safe; a config aceita hex (como mostra o Play Console). */
    private static function certificadoConfere(array $digests, string $esperadoHex): bool
    {
        $esperado = strtolower(str_replace(':', '', $esperadoHex));
        foreach ($digests as $digest) {
            $bruto = base64_decode(strtr((string) $digest, '-_', '+/'), false);
            if ($bruto !== false && hash_equals($esperado, bin2hex($bruto))) {
                return true;
            }
        }

        return false;
    }

    /** OAuth da conta de serviço (JWT RS256 → access token), em cache por 50 min. */
    private static function tokenDeAcesso(string $caminhoCredenciais): string
    {
        return Cache::remember('play_integrity_access_token', 50 * 60, function () use ($caminhoCredenciais): string {
            $conta = json_decode((string) file_get_contents($caminhoCredenciais), true);
            $agora = time();
            $b64 = fn (string $s): string => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
            $cabecalho = $b64(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
            $corpo = $b64(json_encode([
                'iss' => $conta['client_email'],
                'scope' => self::ESCOPO,
                'aud' => $conta['token_uri'],
                'iat' => $agora,
                'exp' => $agora + 3600,
            ]));
            openssl_sign("{$cabecalho}.{$corpo}", $assinatura, $conta['private_key'], OPENSSL_ALGO_SHA256);

            return Http::asForm()->timeout(10)->post($conta['token_uri'], [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => "{$cabecalho}.{$corpo}.".$b64($assinatura),
            ])->throw()->json('access_token');
        });
    }
}

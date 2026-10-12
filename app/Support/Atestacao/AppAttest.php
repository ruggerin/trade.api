<?php

namespace App\Support\Atestacao;

use Throwable;

/**
 * Verificação do App Attest da Apple (docs/67 Fase 3), seguindo "Validating apps that connect to
 * your server" da Apple:
 *
 * Atestação (no vínculo do aparelho): cadeia credCert → intermediário → Apple App Attestation Root
 * CA; nonce = SHA256(authData ‖ SHA256(desafio)) dentro da extensão 1.2.840.113635.100.8.2 do
 * credCert; keyId = SHA256(chave pública do credCert); authData com rpIdHash = SHA256(teamId.bundleId),
 * contador 0, aaguid de produção/desenvolvimento e credentialId = keyId.
 *
 * Asserção (no refresh): assinatura ECDSA da chave atestada sobre SHA256(authenticatorData ‖
 * SHA256(desafio)), rpIdHash e contador crescente.
 *
 * O @expo/app-integrity calcula clientDataHash como SHA256 do desafio em UTF-8 e devolve base64
 * (ver node_modules/@expo/app-integrity/ios/IntegrityModule.swift).
 */
final class AppAttest
{
    /** DER do OID 1.2.840.113635.100.8.2 (extensão com o nonce). */
    private const OID_NONCE = "\x06\x09\x2A\x86\x48\x86\xF7\x63\x64\x08\x02";

    public static function verificarAtestacao(string $keyIdBase64, string $atestacaoBase64, string $desafio): Veredito
    {
        $config = config('seguranca.app_attest');
        if (empty($config['team_id']) || ! is_readable((string) $config['raiz'])) {
            return Veredito::indisponivel('App Attest não configurado no servidor.');
        }

        try {
            $objeto = Cbor::decodificar((string) base64_decode($atestacaoBase64, true));
            $keyId = (string) base64_decode($keyIdBase64, true);

            if (($objeto['fmt'] ?? null) !== 'apple-appattest' || count($objeto['attStmt']['x5c'] ?? []) < 2) {
                return Veredito::falhou('Formato de atestação inesperado.');
            }

            [$credDer, $intermediarioDer] = $objeto['attStmt']['x5c'];
            $authData = (string) $objeto['authData'];
            $cred = self::pem($credDer);
            $intermediario = self::pem($intermediarioDer);
            $raiz = (string) file_get_contents($config['raiz']);

            if (! self::cadeiaValida($cred, $intermediario, $raiz)) {
                return Veredito::falhou('Cadeia de certificados não leva à raiz da Apple.');
            }

            $nonce = hash('sha256', $authData.hash('sha256', $desafio, true), true);
            $posOid = strpos($credDer, self::OID_NONCE);
            if ($posOid === false || ! str_contains(substr($credDer, $posOid), "\x04\x20".$nonce)) {
                return Veredito::falhou('Nonce da atestação não confere com o desafio.');
            }

            $chave = openssl_pkey_get_public($cred);
            $detalhes = $chave ? openssl_pkey_get_details($chave) : false;
            if (! $detalhes || ! isset($detalhes['ec']['x'], $detalhes['ec']['y'])) {
                return Veredito::falhou('Chave do certificado inválida.');
            }
            $ponto = "\x04".str_pad($detalhes['ec']['x'], 32, "\0", STR_PAD_LEFT).str_pad($detalhes['ec']['y'], 32, "\0", STR_PAD_LEFT);
            if (! hash_equals(hash('sha256', $ponto, true), $keyId)) {
                return Veredito::falhou('Identificador da chave não confere.');
            }

            if (! self::rpIdValido($authData, $config)) {
                return Veredito::falhou('App ID da atestação não é o do Horus.');
            }
            if (unpack('N', substr($authData, 33, 4))[1] !== 0) {
                return Veredito::falhou('Contador da atestação diferente de zero.');
            }
            $aaguidEsperado = $config['ambiente'] === 'development' ? 'appattestdevelop' : "appattest\0\0\0\0\0\0\0";
            if (substr($authData, 37, 16) !== $aaguidEsperado) {
                return Veredito::falhou('Ambiente do App Attest não confere.');
            }
            $tamanhoId = unpack('n', substr($authData, 53, 2))[1];
            if (! hash_equals($keyId, substr($authData, 55, $tamanhoId))) {
                return Veredito::falhou('Credencial da atestação não confere com a chave.');
            }

            return Veredito::ok([
                'plataforma' => 'ios',
                'key_id' => $keyIdBase64,
                'chave_attest' => $detalhes['key'],
                'contador' => 0,
            ]);
        } catch (Throwable $e) {
            return Veredito::falhou('Atestação malformada: '.$e->getMessage());
        }
    }

    public static function verificarAssercao(string $assercaoBase64, string $desafio, string $chaveAttestPem, int $contadorAnterior): Veredito
    {
        $config = config('seguranca.app_attest');

        try {
            $objeto = Cbor::decodificar((string) base64_decode($assercaoBase64, true));
            $authData = (string) ($objeto['authenticatorData'] ?? '');
            $assinatura = (string) ($objeto['signature'] ?? '');

            $nonce = hash('sha256', $authData.hash('sha256', $desafio, true), true);
            if (openssl_verify($nonce, $assinatura, $chaveAttestPem, OPENSSL_ALGO_SHA256) !== 1) {
                return Veredito::falhou('Assinatura da asserção inválida.');
            }
            if (! self::rpIdValido($authData, $config)) {
                return Veredito::falhou('App ID da asserção não é o do Horus.');
            }
            $contador = unpack('N', substr($authData, 33, 4))[1];
            if ($contador <= $contadorAnterior) {
                return Veredito::falhou('Contador da asserção não avançou (asserção repetida).');
            }

            return Veredito::ok(['plataforma' => 'ios', 'contador' => $contador]);
        } catch (Throwable $e) {
            return Veredito::falhou('Asserção malformada: '.$e->getMessage());
        }
    }

    private static function rpIdValido(string $authData, array $config): bool
    {
        return hash_equals(hash('sha256', $config['team_id'].'.'.$config['bundle_id'], true), substr($authData, 0, 32));
    }

    private static function cadeiaValida(string $cred, string $intermediario, string $raiz): bool
    {
        foreach ([$cred, $intermediario] as $certificado) {
            $info = openssl_x509_parse($certificado);
            if (! $info || $info['validFrom_time_t'] > time() || $info['validTo_time_t'] < time()) {
                return false;
            }
        }

        return openssl_x509_verify($cred, $intermediario) === 1
            && openssl_x509_verify($intermediario, $raiz) === 1;
    }

    private static function pem(string $der): string
    {
        return "-----BEGIN CERTIFICATE-----\n".chunk_split(base64_encode($der), 64, "\n")."-----END CERTIFICATE-----\n";
    }
}

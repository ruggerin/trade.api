<?php

namespace App\Support;

use App\Models\Dispositivo;
use App\Models\Parametro;
use App\Models\Usuario;
use App\Support\Atestacao\AppAttest;
use App\Support\Atestacao\PlayIntegrity;
use App\Support\Atestacao\Veredito;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;

/**
 * Prova de "app oficial num aparelho real" (docs/67 Fase 3), no vínculo do aparelho (login) e no
 * refresh do token:
 *
 * 1. O app pede um desafio (POST /auth/desafio), de uso único e curto.
 * 2. Gera (uma vez) um par de chaves P-256 e manda a pública no login.
 * 3. Pede a atestação com hash = SHA256(desafio|chave pública): Play Integrity (requestHash) ou
 *    App Attest (challenge). Assim a atestação só vale pra este login e esta chave.
 * 4. A partir daí todo request do app vai assinado com a chave (middleware `assinatura`) — token
 *    copiado pra outro aparelho ou script não serve sem a chave privada.
 *
 * Com ATESTACAO_OBRIGATORIA desligada (padrão, e o único jeito de rodar no Expo Go), atestação
 * ausente ou negativa só vira evento pro ADMIN; ligada, bloqueia com 403. Serviço da Google/Apple
 * indisponível nunca bloqueia.
 */
class AtestacaoAparelho
{
    /** Prefixo DER de uma chave pública EC P-256 (SubjectPublicKeyInfo) antes do ponto não comprimido. */
    private const PREFIXO_SPKI_P256 = '3059301306072a8648ce3d020106082a8648ce3d030107034200';

    public static function obrigatoria(): bool
    {
        return (bool) config('seguranca.atestacao_obrigatoria');
    }

    /** @return array{desafio: string, expira_em: string} */
    public static function emitirDesafio(): array
    {
        $desafio = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $expira = now()->addSeconds((int) config('seguranca.desafio_validade_segundos'));
        DB::table('desafios_atestacao')->insert(['desafio' => $desafio, 'expira_em' => $expira]);

        return ['desafio' => $desafio, 'expira_em' => $expira->toIso8601String()];
    }

    /** Chave pública do app (ponto P-256 não comprimido em base64) → PEM; null se inválida. */
    public static function pem(?string $chaveBase64): ?string
    {
        $ponto = $chaveBase64 ? base64_decode($chaveBase64, true) : false;
        if ($ponto === false || strlen($ponto) !== 65 || $ponto[0] !== "\x04") {
            return null;
        }

        $pem = "-----BEGIN PUBLIC KEY-----\n"
            .chunk_split(base64_encode(hex2bin(self::PREFIXO_SPKI_P256).$ponto), 64, "\n")
            ."-----END PUBLIC KEY-----\n";

        return openssl_pkey_get_public($pem) ? $pem : null;
    }

    public static function hashPedido(string $desafio, string $chaveBase64): string
    {
        return hash('sha256', $desafio.'|'.$chaveBase64);
    }

    /**
     * Verifica a atestação enviada e aplica a política. Grava evento em falha.
     *
     * @param  array{desafio?: string, plataforma?: string, token?: string, key_id?: string, objeto?: string, assercao?: string}|null  $atestacao
     *
     * @throws HttpResponseException 403 ATESTACAO_FALHOU quando obrigatória e não passou
     */
    public static function exigir(Usuario $usuario, ?array $atestacao, ?string $chaveBase64, ?Dispositivo $dispositivo = null): Veredito
    {
        $veredito = self::verificar($atestacao, $chaveBase64, $dispositivo);

        if ($veredito->status === Veredito::FALHOU) {
            Seguranca::registrar(Seguranca::INTEGRIDADE_FALHA, $usuario, $dispositivo, ['motivo' => $veredito->motivo]);
        }

        if (self::obrigatoria() && $veredito->bloqueia()) {
            throw new HttpResponseException(response()->json([
                'message' => 'Não foi possível confirmar que este é o app oficial do Horus neste aparelho.',
                'codigo' => 'ATESTACAO_FALHOU',
                'detalhe' => $veredito->motivo,
            ], 403));
        }

        return $veredito;
    }

    /**
     * Sinais que o próprio app informa (root/jailbreak, emulador). Sozinhos não provam nada — quem
     * prova é a atestação —, mas servem de alerta. INTEGRIDADE_MODO: ALERTA (padrão) ou BLOQUEIO.
     *
     * @param  array{root?: bool, emulador?: bool}|null  $sinais
     */
    public static function avaliarSinais(Usuario $usuario, ?array $sinais): ?string
    {
        $suspeitos = array_keys(array_filter([
            'root' => (bool) ($sinais['root'] ?? false),
            'emulador' => (bool) ($sinais['emulador'] ?? false),
        ]));

        if ($suspeitos === []) {
            return null;
        }

        Seguranca::registrar(Seguranca::INTEGRIDADE_ALERTA, $usuario, null, ['sinais' => $suspeitos]);

        $modo = $usuario->empresa_id === null ? null : Parametro::withoutGlobalScopes()
            ->where('empresa_id', $usuario->empresa_id)
            ->where('chave', 'INTEGRIDADE_MODO')
            ->where('ativo', true)
            ->value('valor');

        if (strtoupper((string) $modo) === 'BLOQUEIO') {
            throw new HttpResponseException(response()->json([
                'message' => 'Este aparelho não pode usar o Horus (sistema modificado ou emulador). Fale com seu gestor.',
                'codigo' => 'INTEGRIDADE_BLOQUEADA',
            ], 403));
        }

        return 'SUSPEITO';
    }

    /** Grava no aparelho a chave, o resultado da atestação e os dados do App Attest (pra asserções). */
    public static function aplicar(Dispositivo $dispositivo, Veredito $veredito, ?string $chaveBase64, ?string $sinal): void
    {
        $detalhes = $dispositivo->integridade_detalhes ?? [];
        if ($veredito->status === Veredito::OK && isset($veredito->dados['chave_attest'])) {
            $detalhes['app_attest'] = [
                'key_id' => $veredito->dados['key_id'],
                'chave' => $veredito->dados['chave_attest'],
                'contador' => $veredito->dados['contador'],
            ];
        } elseif ($veredito->status === Veredito::OK && isset($veredito->dados['contador'], $detalhes['app_attest'])) {
            $detalhes['app_attest']['contador'] = $veredito->dados['contador'];
        }
        $detalhes['ultima_atestacao'] = [
            'status' => $veredito->status,
            'motivo' => $veredito->motivo,
            'em' => now()->toIso8601String(),
        ];

        $dispositivo->update([
            'chave_publica' => self::pem($chaveBase64) ? $chaveBase64 : $dispositivo->chave_publica,
            'integridade_status' => $sinal ?? match ($veredito->status) {
                Veredito::OK => 'ATESTADO',
                Veredito::FALHOU => 'FALHOU',
                default => 'NAO_ATESTADO',
            },
            'integridade_detalhes' => $detalhes,
        ]);
    }

    private static function verificar(?array $atestacao, ?string $chaveBase64, ?Dispositivo $dispositivo): Veredito
    {
        if (empty($atestacao['desafio'])) {
            return Veredito::naoEnviada();
        }

        $consumido = DB::table('desafios_atestacao')
            ->where('desafio', $atestacao['desafio'])
            ->whereNull('usado_em')
            ->where('expira_em', '>', now())
            ->update(['usado_em' => now()]);
        if ($consumido !== 1) {
            return Veredito::falhou('Desafio inválido, vencido ou já usado.');
        }

        if (self::pem($chaveBase64) === null) {
            return Veredito::falhou('Chave pública do aparelho ausente ou inválida.');
        }

        $hash = self::hashPedido($atestacao['desafio'], $chaveBase64);
        $appAttest = $dispositivo?->integridade_detalhes['app_attest'] ?? null;

        return match (true) {
            ($atestacao['plataforma'] ?? null) === 'android' && ! empty($atestacao['token']) => PlayIntegrity::verificar($atestacao['token'], $hash),
            ($atestacao['plataforma'] ?? null) === 'ios' && ! empty($atestacao['assercao']) && $appAttest !== null => AppAttest::verificarAssercao(
                $atestacao['assercao'], $hash, $appAttest['chave'], (int) $appAttest['contador'],
            ),
            ($atestacao['plataforma'] ?? null) === 'ios' && ! empty($atestacao['key_id']) && ! empty($atestacao['objeto']) => AppAttest::verificarAtestacao(
                $atestacao['key_id'], $atestacao['objeto'], $hash,
            ),
            default => Veredito::falhou('Atestação incompleta.'),
        };
    }
}

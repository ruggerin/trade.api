<?php

namespace App\Support;

use App\Models\Integracao;
use App\Models\PersonalAccessToken;
use Illuminate\Support\Str;

/**
 * Emissão e troca dos tokens de integração (docs/96 §4.2 e §6).
 *
 * - Formato `horus_live_<40 aleatórios>` (`horus_test_` fora de produção): o prefixo deixa os
 *   scanners de segredos reconhecerem o token se ele vazar num repositório, e o suporte reconhecer
 *   o que o cliente colou. Sem o `id|` do Sanctum: o Sanctum acha o token só pelo hash.
 * - Só o SHA-256 vai pro banco; o texto puro sai uma vez, na resposta de quem criou.
 * - Canal `integracao`: SessaoApp::tokenAceito só aceita esse token em /api/integracao/*, e
 *   nenhum outro token lá.
 */
class TokenIntegracao
{
    public const CANAL = 'integracao';

    /** Validades oferecidas na tela, em dias; null = sem validade. */
    public const VALIDADES = [30, 90, 180, 365];

    /** Quanto tempo o token antigo segue valendo depois de "Gerar novo token". */
    public const TOLERANCIA_ROTACAO_DIAS = 7;

    public const MOTIVO_REVOGADA = 'INTEGRACAO_REVOGADA';

    public static function prefixo(): string
    {
        return app()->isProduction() ? 'horus_live_' : 'horus_test_';
    }

    public static function emitir(Integracao $integracao, ?int $validadeDias): string
    {
        $texto = self::prefixo().Str::random(40);

        $token = new PersonalAccessToken;
        $token->forceFill([
            'tokenable_type' => $integracao->getMorphClass(),
            'tokenable_id' => $integracao->id,
            'name' => 'integracao',
            'token' => hash('sha256', $texto),
            'abilities' => $integracao->escopos,
            'expires_at' => $validadeDias ? now()->addDays($validadeDias) : null,
            'canal' => self::CANAL,
            'prefixo_visivel' => mb_substr($texto, 0, strlen(self::prefixo()) + 4),
        ])->save();

        return $texto;
    }

    /**
     * Novo token; os que estavam valendo passam a vencer em TOLERANCIA_ROTACAO_DIAS (ou antes, se
     * já iam vencer antes), pro cliente trocar sem parar a integração.
     */
    public static function rotacionar(Integracao $integracao, ?int $validadeDias): string
    {
        $limite = now()->addDays(self::TOLERANCIA_ROTACAO_DIAS);

        $integracao->tokens()
            ->whereNull('revogado_em')
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', $limite))
            ->update(['expires_at' => $limite]);

        return self::emitir($integracao, $validadeDias);
    }

    public static function revogarTodos(Integracao $integracao): void
    {
        $integracao->tokens()->whereNull('revogado_em')->update([
            'revogado_em' => now(),
            'revogado_motivo' => self::MOTIVO_REVOGADA,
        ]);
    }

    /** Escopos editados na tela valem já: o token guarda a cópia em `abilities`. */
    public static function sincronizarEscopos(Integracao $integracao): void
    {
        $integracao->tokens()->update(['abilities' => json_encode($integracao->escopos)]);
    }
}

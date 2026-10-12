<?php

namespace App\Support;

use App\Models\Dispositivo;
use App\Models\EventoSeguranca;
use App\Models\Usuario;

/**
 * Ponto único de gravação em `eventos_seguranca` (docs/67 §4.2). Nunca derruba a requisição:
 * falha ao registrar vira report() e segue.
 */
class Seguranca
{
    public const LOGIN = 'LOGIN';

    public const LOGIN_NEGADO = 'LOGIN_NEGADO';

    public const APARELHO_VINCULADO = 'APARELHO_VINCULADO';

    public const TROCA_APARELHO = 'TROCA_APARELHO';

    /** Modo "só registra" (TROCA_APARELHO_SO_REGISTRA): seria bloqueada, mas trocou. */
    public const TROCA_SERIA_BLOQUEADA = 'TROCA_SERIA_BLOQUEADA';

    public const TROCA_BLOQUEADA = 'TROCA_BLOQUEADA';

    public const APARELHO_DESVINCULADO = 'APARELHO_DESVINCULADO';

    /** ADMIN liberou uma troca extra ("Liberar nova troca" no cadastro do usuário). */
    public const TROCA_LIBERADA = 'TROCA_LIBERADA';

    public const SOLICITACAO_APROVADA = 'SOLICITACAO_APROVADA';

    public const SOLICITACAO_RECUSADA = 'SOLICITACAO_RECUSADA';

    public const TOKEN_REAPROVEITADO = 'TOKEN_REAPROVEITADO';

    public const ASSINATURA_INVALIDA = 'ASSINATURA_INVALIDA';

    public const INTEGRIDADE_FALHA = 'INTEGRIDADE_FALHA';

    public const INTEGRIDADE_ALERTA = 'INTEGRIDADE_ALERTA';

    public const LOCALIZACAO_SIMULADA = 'LOCALIZACAO_SIMULADA';

    public const VIAGEM_IMPOSSIVEL = 'VIAGEM_IMPOSSIVEL';

    public const APP_DESATUALIZADO = 'APP_DESATUALIZADO';

    public static function registrar(string $tipo, ?Usuario $usuario, ?Dispositivo $dispositivo = null, array $detalhes = []): void
    {
        try {
            $request = request();
            EventoSeguranca::create([
                'empresa_id' => $usuario?->empresa_id,
                'usuario_id' => $usuario?->id,
                'dispositivo_id' => $dispositivo?->id,
                'tipo' => $tipo,
                'detalhes' => $detalhes ?: null,
                'ip' => $request->ip(),
                'user_agent' => mb_substr((string) $request->userAgent(), 0, 255) ?: null,
            ]);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /** Evita inundar a trilha com o mesmo alerta (ex.: GPS simulado a cada ping). */
    public static function registradoRecentemente(string $tipo, Usuario $usuario, int $minutos): bool
    {
        return EventoSeguranca::query()
            ->where('usuario_id', $usuario->id)
            ->where('tipo', $tipo)
            ->where('created_at', '>=', now()->subMinutes($minutos))
            ->exists();
    }
}

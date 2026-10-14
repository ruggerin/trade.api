<?php

namespace App\Support;

use Sentry\Event;
use Sentry\EventHint;
use Sentry\UserDataBag;

/**
 * Contexto que acompanha todo evento do Sentry: QUEM (uuid do usuário, nunca e-mail ou nome) e DE
 * QUAL EMPRESA, pra o time achar "o erro do cliente X" sem abrir dado pessoal no painel.
 *
 * Roda no momento do envio (`before_send` do config/sentry.php), quando o usuário da requisição já
 * foi resolvido. É um método estático de propósito: closure no config quebra `config:cache`.
 */
final class ContextoSentry
{
    public static function beforeSend(Event $event, ?EventHint $hint = null): ?Event
    {
        try {
            $usuario = auth()->user();
            if ($usuario) {
                $event->setUser(UserDataBag::createFromUserIdentifier((string) $usuario->uuid));
                $event->setTag('user_type', (string) ($usuario->user_type->value ?? $usuario->user_type));
                if ($usuario->empresa_id) {
                    $event->setTag('empresa_id', (string) $usuario->empresa_id);
                }
            }
        } catch (\Throwable) {
            // Contexto é cortesia: nunca pode impedir o evento de sair.
        }

        return $event;
    }
}

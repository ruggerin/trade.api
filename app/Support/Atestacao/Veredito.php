<?php

namespace App\Support\Atestacao;

/**
 * Resultado de uma verificação de atestação (docs/67 §5.3):
 * - OK: app oficial num aparelho íntegro;
 * - FALHOU: veredito negativo (app modificado, emulador, chave/desafio não batem) — bloqueia
 *   quando a atestação é obrigatória;
 * - INDISPONIVEL: serviço da Google/Apple fora do ar ou não configurado — nunca bloqueia;
 * - NAO_ENVIADA: o app não mandou atestação (Expo Go, versão antiga) — bloqueia só se obrigatória.
 */
final class Veredito
{
    public const OK = 'OK';

    public const FALHOU = 'FALHOU';

    public const INDISPONIVEL = 'INDISPONIVEL';

    public const NAO_ENVIADA = 'NAO_ENVIADA';

    private function __construct(
        public readonly string $status,
        public readonly ?string $motivo = null,
        public readonly array $dados = [],
    ) {}

    public static function ok(array $dados = []): self
    {
        return new self(self::OK, null, $dados);
    }

    public static function falhou(string $motivo): self
    {
        return new self(self::FALHOU, $motivo);
    }

    public static function indisponivel(string $motivo): self
    {
        return new self(self::INDISPONIVEL, $motivo);
    }

    public static function naoEnviada(): self
    {
        return new self(self::NAO_ENVIADA, 'Atestação não enviada pelo app.');
    }

    /** Bloqueia o acesso quando a atestação é obrigatória. */
    public function bloqueia(): bool
    {
        return $this->status === self::FALHOU || $this->status === self::NAO_ENVIADA;
    }
}

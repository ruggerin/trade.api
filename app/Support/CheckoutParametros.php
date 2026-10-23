<?php

namespace App\Support;

use App\Models\Empresa;
use App\Models\Parametro;

/**
 * Parâmetros do checkout pela saída detectada (docs/92 §2). Cada peça é independente e, sem
 * nada cadastrado, o comportamento é o de antes:
 *
 * - A. `CHECKOUT_RAIO_METROS` (ausente/inativo/0 = sem raio — diferente do check-in, decisão 2)
 *   + `CHECKOUT_FORA_DO_RAIO` (REGISTRA/AVISA/BLOQUEIA, padrão REGISTRA).
 * - B. `CHECKOUT_SUGERIR_SAIDA` (padrão false).
 * - C. `CHECKOUT_AVISO_SAIDA_MINUTOS` (ausente/inativo/0 = sem aviso).
 * - D. `CHECKOUT_AUTOMATICO_MINUTOS` (ausente/inativo/0 = nunca fecha sozinho; mínimo 30, decisão 5).
 */
final class CheckoutParametros
{
    public const MODOS_FORA_DO_RAIO = ['REGISTRA', 'AVISA', 'BLOQUEIA'];

    /** Fechar sozinho antes disso pega GPS ruim e loja com coordenada errada (decisão 5). */
    public const AUTOMATICO_MINIMO_MINUTOS = 30;

    /** null = a empresa não exige raio no checkout. */
    public static function raioMetros(Empresa $empresa): ?int
    {
        $valor = self::valor($empresa, 'CHECKOUT_RAIO_METROS');

        return is_numeric($valor) && (int) $valor > 0 ? (int) $valor : null;
    }

    public static function foraDoRaio(Empresa $empresa): string
    {
        $valor = strtoupper(trim((string) self::valor($empresa, 'CHECKOUT_FORA_DO_RAIO')));

        return in_array($valor, self::MODOS_FORA_DO_RAIO, true) ? $valor : 'REGISTRA';
    }

    public static function sugerirSaida(Empresa $empresa): bool
    {
        return in_array(strtolower(trim((string) self::valor($empresa, 'CHECKOUT_SUGERIR_SAIDA'))), ['1', 'true', 'sim', 'yes'], true);
    }

    /** 0 = sem aviso por push. */
    public static function avisoSaidaMinutos(Empresa $empresa): int
    {
        $valor = self::valor($empresa, 'CHECKOUT_AVISO_SAIDA_MINUTOS');

        return is_numeric($valor) ? max(0, (int) $valor) : 0;
    }

    /** 0 = nunca fecha sozinho. Abaixo do mínimo sobe pro mínimo. */
    public static function automaticoMinutos(Empresa $empresa): int
    {
        $valor = self::valor($empresa, 'CHECKOUT_AUTOMATICO_MINUTOS');
        $minutos = is_numeric($valor) ? (int) $valor : 0;

        return $minutos > 0 ? max(self::AUTOMATICO_MINIMO_MINUTOS, $minutos) : 0;
    }

    /** Valor de um parâmetro ATIVO da empresa, ou null se ausente/inativo. */
    private static function valor(Empresa $empresa, string $chave): ?string
    {
        return Parametro::query()
            ->withoutGlobalScopes()
            ->where('empresa_id', $empresa->id)
            ->where('chave', $chave)
            ->where('ativo', true)
            ->value('valor');
    }
}

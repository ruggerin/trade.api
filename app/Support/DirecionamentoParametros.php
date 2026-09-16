<?php

namespace App\Support;

use App\Models\Empresa;
use App\Models\Parametro;

/**
 * `DIRECIONAMENTO_BLOQUEIA_CHECKOUT` — mesmo padrão de App\Support\AutonomiaAgenda. Ausente/
 * inativo = não bloqueia (só avisa, informativo). Ativo com valor truthy = o checkout recusa
 * (422) enquanto existir formulário de Direcionamento pendente na visita. Ver
 * docs/25-DIRECIONAMENTO-ORDEM-SERVICO.md §2 decisão 5 / §8.
 */
class DirecionamentoParametros
{
    private const VALORES_VERDADEIROS = ['1', 'true', 'sim', 'yes'];

    public static function bloqueiaCheckout(Empresa $empresa): bool
    {
        $parametro = Parametro::query()
            ->where('empresa_id', $empresa->id)
            ->where('chave', 'DIRECIONAMENTO_BLOQUEIA_CHECKOUT')
            ->where('ativo', true)
            ->first();

        if (! $parametro) {
            return false;
        }

        return in_array(strtolower((string) $parametro->valor), self::VALORES_VERDADEIROS, true);
    }
}

<?php

namespace App\Support;

use App\Enums\AutonomiaPromotor;
use App\Models\Empresa;
use App\Models\Parametro;

/**
 * Autonomia do promotor sobre sortimento (vincular produto já existente a um PDV) e catálogo
 * (cadastrar produto que ainda não existe), configuráveis por empresa via dois `Parametro`
 * independentes — mesmo padrão de leitura de AutonomiaAgenda/VisibilidadePontosVenda, só que com
 * 3 valores em vez de truthy/falsy. Ver docs/14-SORTIMENTO-PONTO-VENDA.md §9.
 */
class AutonomiaSortimento
{
    // Default permissivo — bate com a ideia original de "dar o aplicativo na mão do promotor e
    // deixar rolando" sem a empresa precisar configurar nada.
    public static function paraVincular(Empresa $empresa): AutonomiaPromotor
    {
        return self::ler($empresa, 'SORTIMENTO_AUTONOMIA_PROMOTOR', AutonomiaPromotor::AUTONOMO);
    }

    // Default mais conservador de propósito — cadastrar produto novo polui o catálogo da
    // empresa inteira, não só a carteira de um PDV.
    public static function paraCadastro(Empresa $empresa): AutonomiaPromotor
    {
        return self::ler($empresa, 'CATALOGO_AUTONOMIA_PROMOTOR', AutonomiaPromotor::REQUER_APROVACAO);
    }

    private static function ler(Empresa $empresa, string $chave, AutonomiaPromotor $default): AutonomiaPromotor
    {
        $parametro = Parametro::query()
            ->where('empresa_id', $empresa->id)
            ->where('chave', $chave)
            ->where('ativo', true)
            ->first();

        if (! $parametro) {
            return $default;
        }

        return AutonomiaPromotor::tryFrom(strtoupper((string) $parametro->valor)) ?? $default;
    }
}

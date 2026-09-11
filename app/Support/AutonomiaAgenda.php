<?php

namespace App\Support;

use App\Models\Empresa;
use App\Models\Parametro;

/**
 * Autonomia do promotor sobre a própria agenda (criar/reagendar/cancelar a própria
 * OrdemServico), configurável por empresa via `Parametro` `AGENDA_REQUER_APROVACAO` — mesmo
 * padrão de VisibilidadePontosVenda. Ausente/inativo = modo autônomo (default: promotor aplica
 * a ação na hora). Ativo com valor truthy = modo aprovação (a ação vira uma solicitação
 * pendente até o gestor decidir). Ver docs/13-AGENDA-MOBILE-E-AUTONOMIA.md.
 */
class AutonomiaAgenda
{
    private const VALORES_VERDADEIROS = ['1', 'true', 'sim', 'yes'];

    public static function requerAprovacao(Empresa $empresa): bool
    {
        $parametro = Parametro::query()
            ->where('empresa_id', $empresa->id)
            ->where('chave', 'AGENDA_REQUER_APROVACAO')
            ->where('ativo', true)
            ->first();

        if (! $parametro) {
            return false;
        }

        return in_array(strtolower((string) $parametro->valor), self::VALORES_VERDADEIROS, true);
    }
}

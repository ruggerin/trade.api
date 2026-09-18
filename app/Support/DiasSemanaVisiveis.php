<?php

namespace App\Support;

use App\Models\Empresa;
use App\Models\Parametro;

/**
 * Dias da semana (0=domingo..6=sábado) visíveis pro Planejador de Visitas — respeita os mesmos
 * parâmetros `PLANEJADOR_VISITAS_OMITIR_DOMINGO`/`PLANEJADOR_VISITAS_OMITIR_SABADO` que o quadro
 * semanal já lê no admin (ver docs/10-AGENDA-VISITA.md §8.1). Usado aqui pra que o Relatório de
 * Rota agrupe pelos mesmos dias que o quadro mostra — uma empresa que omite domingo no quadro não
 * deveria ver domingo aparecer do nada no PDF. Mesmo padrão de leitura de `Parametro` já usado por
 * CancelamentoRegistro/AutonomiaAgenda.
 */
class DiasSemanaVisiveis
{
    private const VALORES_VERDADEIROS = ['1', 'true', 'sim', 'yes'];

    /** @return int[] dia_semana em ordem (0..6), já filtrado. */
    public static function paraEmpresa(Empresa $empresa): array
    {
        $omitirDomingo = self::parametroAtivo($empresa, 'PLANEJADOR_VISITAS_OMITIR_DOMINGO');
        $omitirSabado = self::parametroAtivo($empresa, 'PLANEJADOR_VISITAS_OMITIR_SABADO');

        return array_values(array_filter(
            range(0, 6),
            fn (int $dia) => ! ($dia === 0 && $omitirDomingo) && ! ($dia === 6 && $omitirSabado),
        ));
    }

    private static function parametroAtivo(Empresa $empresa, string $chave): bool
    {
        $parametro = Parametro::query()
            ->where('empresa_id', $empresa->id)
            ->where('chave', $chave)
            ->where('ativo', true)
            ->first();

        if (! $parametro) {
            return false;
        }

        return in_array(strtolower((string) $parametro->valor), self::VALORES_VERDADEIROS, true);
    }
}

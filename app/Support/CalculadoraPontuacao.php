<?php

namespace App\Support;

use App\Enums\TipoCampoRegistro;
use App\Models\TipoRegistro;

/**
 * % de compliance de um formulário respondido — decisão 5 de
 * docs/20-FORMULARIO-DINAMICO-CAMPANHA.md. V1 sem peso por pergunta: conta só campos
 * BOOLEANO/SORTIMENTO (os únicos com um "passou"/"não passou" natural) sobre o total de campos
 * scoreáveis do tipo — campos condicionais cuja condição não foi satisfeita ficam de fora da
 * conta (não é justo penalizar uma pergunta que nem deveria ter aparecido pro promotor).
 */
class CalculadoraPontuacao
{
    /** @return int|null 0-100, ou null quando o tipo não usa pontuação ou não tem nenhum campo scoreável aplicável. */
    public static function calcular(TipoRegistro $tipoRegistro, ?array $valoresCampos): ?int
    {
        if (! $tipoRegistro->usa_pontuacao) {
            return null;
        }

        $valores = $valoresCampos ?? [];
        $total = 0;
        $passou = 0;

        foreach ($tipoRegistro->campos as $campo) {
            if (! in_array($campo->tipo_campo, [TipoCampoRegistro::BOOLEANO, TipoCampoRegistro::SORTIMENTO], true)) {
                continue;
            }

            if ($campo->depende_de_campo_id) {
                $campoPai = $tipoRegistro->campos->firstWhere('id', $campo->depende_de_campo_id);
                $condicaoSatisfeita = $campoPai && (($valores[$campoPai->chave] ?? null) === $campo->depende_de_valor);
                if (! $condicaoSatisfeita) {
                    continue;
                }
            }

            $total++;
            $valor = $valores[$campo->chave] ?? null;

            if ($campo->tipo_campo === TipoCampoRegistro::BOOLEANO) {
                if ($valor === '1') {
                    $passou++;
                }

                continue;
            }

            // SORTIMENTO — "passou" quando não há nenhum produto marcado ausente.
            $decodificado = is_string($valor) ? json_decode($valor, true) : null;
            if (is_array($decodificado) && empty($decodificado['ausentes'])) {
                $passou++;
            }
        }

        return $total > 0 ? (int) round($passou / $total * 100) : null;
    }
}

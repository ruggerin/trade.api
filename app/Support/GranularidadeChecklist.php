<?php

namespace App\Support;

use App\Enums\GranularidadeResposta;
use App\Models\TipoRegistro;

/**
 * Resolve se um TipoRegistro (uma "pergunta" do checklist, ex.: Ponto Natural, Precificado)
 * exige resposta por produto individual ou aceita nível linha/seção — ver
 * docs/16-GRANULARIDADE-CHECKLIST-AUDITORIA.md §4, opção (a).
 *
 * Exceção por seção tem prioridade sobre o padrão do tipo; sem seção conhecida (registro sem
 * produto nem vínculo a SECAO), só o padrão se aplica. `null` = sem regra nenhuma — comportamento
 * livre atual, o promotor escolhe o vínculo como sempre escolheu.
 */
class GranularidadeChecklist
{
    public static function resolver(TipoRegistro $tipoRegistro, ?int $secaoAuditoriaId): ?GranularidadeResposta
    {
        if ($secaoAuditoriaId) {
            $excecao = $tipoRegistro->excecoesGranularidade->firstWhere('secao_auditoria_id', $secaoAuditoriaId);
            if ($excecao) {
                return $excecao->granularidade;
            }
        }

        return $tipoRegistro->granularidade_padrao;
    }
}

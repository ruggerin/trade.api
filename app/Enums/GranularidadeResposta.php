<?php

namespace App\Enums;

/**
 * Granularidade exigida da resposta de um TipoRegistro (uma "pergunta" do checklist) — LINHA
 * (uma resposta cobre a seção inteira) ou PRODUTO (precisa de um produto específico vinculado).
 * Ver docs/16-GRANULARIDADE-CHECKLIST-AUDITORIA.md.
 */
enum GranularidadeResposta: string
{
    case LINHA = 'LINHA';
    case PRODUTO = 'PRODUTO';
}

<?php

namespace App\Enums;

/**
 * Origem da lista de produtos de um campo `SORTIMENTO` (docs/20-FORMULARIO-DINAMICO-CAMPANHA.md
 * decisão 3) — só usado quando `CampoTipoRegistro.tipo_campo = SORTIMENTO`.
 */
enum SortimentoOrigemCampo: string
{
    // Busca ao vivo o SortimentoPontoVenda (docs/14-SORTIMENTO-PONTO-VENDA.md) do recorte
    // configurado (sortimento_tipo_vinculo + secao/departamento/marca), pro PDV da visita.
    case DINAMICO = 'DINAMICO';
    // Lista explícita de produtos escolhida no cadastro do campo (campo_tipo_registro_produtos),
    // ignorando o sortimento real do PDV — pra quando o gestor quer cravar um conjunto sempre.
    case FIXO = 'FIXO';
}

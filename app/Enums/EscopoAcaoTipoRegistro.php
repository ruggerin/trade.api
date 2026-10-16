<?php

namespace App\Enums;

/**
 * Só tem sentido quando `tipos_registro.acao_obrigatoria` é true — define QUANDO esse tipo
 * aparece como pendência ("Ação") na visita, em vez de só uma opção disponível no Registro
 * geral. Ver docs/05-APP-MOBILE-UX.md §3.6.
 */
enum EscopoAcaoTipoRegistro: string
{
    case SEMPRE = 'SEMPRE';
    case CAMPANHA = 'CAMPANHA';
    case CONTRATO = 'CONTRATO';
    // Só nas lojas/redes escolhidas no próprio TipoRegistro (pivôs tipo_registro_pontos_venda /
    // tipo_registro_redes_lojas) — loja OU rede basta; as duas listas vazias = todas as lojas.
    // Ver docs/40-ACAO-OBRIGATORIA-LOJA-REDE.md.
    case LOJA_REDE = 'LOJA_REDE';
    // Onde nenhum item do Mix do PDV cobre o recorte (seção/departamento/marca) escolhido no
    // próprio TipoRegistro — levantamento de concorrência onde a linha não está. Mix vazio não
    // cobra. Ver docs/80-MONITORAMENTO-LINHA-AUSENTE.md §5.1 e App\Support\LinhaAusente.
    case LINHA_AUSENTE = 'LINHA_AUSENTE';
}

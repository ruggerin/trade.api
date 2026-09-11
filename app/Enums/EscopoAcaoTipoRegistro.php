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
}

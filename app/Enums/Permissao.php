<?php

namespace App\Enums;

/**
 * Catálogo fixo de permissões atribuíveis a um `Perfil` — definido em código, só a StoneUp
 * adiciona uma permissão nova (o que já exige endpoint novo mesmo). Cada empresa decide quais
 * dessas permissões cada perfil seu tem, mas não pode inventar uma permissão que não exista
 * aqui. Ver EnsurePermissao e docs/02-API-BACKEND.md.
 */
enum Permissao: string
{
    case PONTOS_VENDA_GERENCIAR = 'pontos_venda.gerenciar';
    case CATALOGO_GERENCIAR = 'catalogo.gerenciar';
    case CAMPANHAS_GERENCIAR = 'campanhas.gerenciar';
    case PARAMETROS_GERENCIAR = 'parametros.gerenciar';
    case USUARIOS_GERENCIAR = 'usuarios.gerenciar';
    case CONTRATOS_GERENCIAR = 'contratos.gerenciar';
    case ORDENS_SERVICO_GERENCIAR = 'ordens_servico.gerenciar';
    case CENTROS_CUSTO_GERENCIAR = 'centros_custo.gerenciar';

    // Única permissão do catálogo com verbo "visualizar" em vez de "gerenciar" — não existe
    // nada pra gerenciar aqui, só ler. Diferente do resto, pode ser atribuída via Perfil a um
    // usuário PROMOTOR também (não só GESTOR) — "promotor supervisor" enxerga todos os PDVs da
    // empresa no mobile, ignorando vínculo. Ver docs/12-VISIBILIDADE-PONTOS-DE-VENDA.md.
    case PONTOS_VENDA_VISUALIZAR_TODOS = 'pontos_venda.visualizar_todos';

    // Intervenção administrativa em Visita: cancelar, forçar checkout com horário real, corrigir
    // horários de entrada/saída — sempre com motivo obrigatório e log de auditoria. Verbo
    // "intervir" (não "gerenciar"): é conserto pontual de dado de campo, não um CRUD. Ação de
    // supervisão — o EnsurePermissao só a consulta pra GESTOR, nunca pra PROMOTOR. Ver
    // docs/15-INTERVENCAO-ADMINISTRATIVA-VISITA.md.
    case VISITAS_INTERVIR = 'visitas.intervir';

    // Ver o mapa ao vivo com a posição dos promotores (docs/11-RASTREAMENTO-TEMPO-REAL.md) —
    // verbo "visualizar" porque não há nada pra gerenciar, só ler. Localização de colega é dado
    // pessoal, por isso permissão própria em vez de carona em outra.
    case RASTREAMENTO_VISUALIZAR = 'rastreamento.visualizar';

    // Gravar pedidos do ERP (docs/28 §4.2) — pensada pro integrador externo (um usuário ADMIN de
    // serviço, ou um perfil de GESTOR dedicado), não pra digitação manual no admin.
    case PEDIDOS_GERENCIAR = 'pedidos.gerenciar';
}

// Tipo de visita (tag colorida) e agenda de visita reaproveitam ORDENS_SERVICO_GERENCIAR — são
// dado satélite da mesma responsabilidade, ver docs/10-AGENDA-VISITA.md e rotas em routes/api.php.

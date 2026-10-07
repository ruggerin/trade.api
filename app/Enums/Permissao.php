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

    // Rota do dia (docs/48-ROTA-DO-DIA.md) — trajeto histórico de um promotor num dia (por onde
    // passou, paradas fora de loja). Separada do mapa ao vivo de propósito: ver o histórico de
    // posição é mais sensível que ver a posição de agora.
    case RASTREAMENTO_TRAJETO = 'rastreamento.trajeto';

    // Gravar pedidos do ERP (docs/28 §4.2) — pensada pro integrador externo (um usuário ADMIN de
    // serviço, ou um perfil de GESTOR dedicado), não pra digitação manual no admin.
    case PEDIDOS_GERENCIAR = 'pedidos.gerenciar';

    // Planos de Ação (docs/37-PLANOS-DE-ACAO.md §6) — fatiadas por ação, não um "gerenciar"
    // único: quem movimenta etapa no dia a dia ("operacional") não é necessariamente quem tem
    // autoridade pra dar o problema como resolvido (concluir). Permite, por exemplo, um perfil
    // "Supervisor de Vendas" só com planos_acao.*, sem nada de campanha/contrato.
    case PLANOS_ACAO_VISUALIZAR = 'planos_acao.visualizar';
    case PLANOS_ACAO_CRIAR = 'planos_acao.criar';
    case PLANOS_ACAO_MOVIMENTAR_ETAPA = 'planos_acao.movimentar_etapa';
    case PLANOS_ACAO_CONCLUIR = 'planos_acao.concluir';
    case PLANOS_ACAO_CANCELAR = 'planos_acao.cancelar';

    // Pedido de Venda digitado pelo vendedor (docs/38-PEDIDO-VENDEDOR.md §5) — domínio próprio,
    // não reaproveita `pedidos.*` (que é o Pedido somente-leitura do ERP). Como
    // pontos_venda.visualizar_todos, vale também pra PROMOTOR: `criar` é o que "liga o modo
    // Vendedor" no mobile. Checadas no controller via App\Support\PermissaoPedidoVenda, não pelo
    // EnsurePermissao (que nunca libera PROMOTOR).
    case PEDIDOS_VENDA_VISUALIZAR = 'pedidos_venda.visualizar';
    case PEDIDOS_VENDA_CRIAR = 'pedidos_venda.criar';
    case PEDIDOS_VENDA_APROVAR = 'pedidos_venda.aprovar';

    // Gerador de relatórios (docs/60 §3.3): criar/editar/excluir/duplicar relatório salvo. Ver e
    // executar continua no gate dos relatórios atuais (ADMIN/GESTOR).
    case RELATORIOS_PERSONALIZADOS_GERENCIAR = 'relatorios.personalizados.gerenciar';

    // Acesso a tela (docs/64-CONTROLE-DE-ACESSO-POR-TELA.md): "vê a tela no menu e lê os dados
    // dela". Só existem pras telas que não tinham permissão de leitura — onde já existe uma
    // (planos_acao.visualizar, rastreamento.*, usuarios.gerenciar, contratos.gerenciar...), é ela
    // que faz o papel de tela. A API só barra as rotas exclusivas da tela; as de leitura aberta
    // (lojas, catálogo, OS, parâmetros) seguem abertas porque o app e os filtros de outras telas
    // dependem delas — nessas, a tela some do menu e a URL mostra "sem acesso".
    case TELA_OPERACAO_DIA = 'tela.operacao_dia';
    case TELA_ATIVIDADES = 'tela.atividades';
    case TELA_VISITAS = 'tela.visitas';
    case TELA_REGISTROS = 'tela.registros';
    case TELA_ORDENS_SERVICO = 'tela.ordens_servico';
    case TELA_CAMPANHAS = 'tela.campanhas';
    case TELA_RELATORIOS = 'tela.relatorios';
    case TELA_LOJAS = 'tela.lojas';
    case TELA_CATALOGO = 'tela.catalogo';
    case TELA_FORMULARIOS = 'tela.formularios';
    case TELA_CONFIGURACOES = 'tela.configuracoes';

    /** @return list<self> */
    public static function telas(): array
    {
        return array_values(array_filter(self::cases(), fn (self $p) => str_starts_with($p->value, 'tela.')));
    }

    /**
     * Mensagens de ação marcada sem a tela dela (docs/64 §3) — vazio = perfil consistente.
     * Valores desconhecidos são ignorados aqui (o `Rule::in` do request já os recusa).
     *
     * @param  array<int, mixed>  $valores
     * @return list<string>
     */
    public static function semTela(array $valores): array
    {
        $erros = [];
        foreach ($valores as $valor) {
            $tela = is_string($valor) ? self::tryFrom($valor)?->telaExigida() : null;
            if ($tela !== null && ! in_array($tela->value, $valores, true)) {
                $erros[] = "A permissão {$valor} exige o acesso à tela ({$tela->value}).";
            }
        }

        return $erros;
    }

    /**
     * Ação que só faz sentido com a tela marcada (docs/64 §3, regra de consistência): o perfil
     * não salva `catalogo.gerenciar` sem `tela.catalogo`.
     */
    public function telaExigida(): ?self
    {
        return match ($this) {
            self::ORDENS_SERVICO_GERENCIAR => self::TELA_ORDENS_SERVICO,
            self::CAMPANHAS_GERENCIAR => self::TELA_CAMPANHAS,
            self::RELATORIOS_PERSONALIZADOS_GERENCIAR => self::TELA_RELATORIOS,
            self::PONTOS_VENDA_GERENCIAR => self::TELA_LOJAS,
            self::CATALOGO_GERENCIAR => self::TELA_CATALOGO,
            self::PARAMETROS_GERENCIAR => self::TELA_CONFIGURACOES,
            default => null,
        };
    }
}

// Tipo de visita (tag colorida) e agenda de visita reaproveitam ORDENS_SERVICO_GERENCIAR — são
// dado satélite da mesma responsabilidade, ver docs/10-AGENDA-VISITA.md e rotas em routes/api.php.

<?php

namespace App\Support;

/**
 * Como cada parâmetro do {@see ParametrosPadrao::CATALOGO} aparece e é validado na tela de
 * Configurações (docs/62): área, nome legível, ajuda, tipo de controle e faixa.
 *
 * O valor padrão NÃO mora aqui — continua em ParametrosPadrao (que é igual ao fallback de cada
 * Support). Um parâmetro novo no catálogo sem entrada aqui cai na área "avancado" só com a
 * descrição técnica; o teste ParametrosMetadadosTest trava isso pra ninguém esquecer.
 *
 * Tipos: bool, numero, numero_ou_zero (0 = desligado), numero_ou_sem_limite (desativar a linha =
 * sem limite, ver CHECKIN_RAIO_METROS), lista, hora (HH:mm).
 * `depende_de`: a opção só faz sentido com outra chave ligada (bool true ou número > 0).
 */
final class ParametrosMetadados
{
    public const AREAS = [
        'visitas' => ['rotulo' => 'Visitas e check-in', 'ajuda' => 'Como a visita começa, termina e é cobrada.'],
        'agenda' => ['rotulo' => 'Agenda e autonomia', 'ajuda' => 'O que o promotor pode fazer sozinho e o que passa por aprovação.'],
        'rastreamento' => ['rotulo' => 'Rastreamento', 'ajuda' => 'Posição dos promotores no Mapa ao vivo e na Rota do dia.'],
        'pedidos' => ['rotulo' => 'Pedidos e faturamento', 'ajuda' => 'Regras de pedido de venda.'],
        'catalogo' => ['rotulo' => 'Catálogo de produtos', 'ajuda' => 'Regras do cadastro de produto.'],
        'paineis' => ['rotulo' => 'Painéis e relatórios', 'ajuda' => 'Atualização e avisos dos painéis do admin.'],
        'aplicativo' => ['rotulo' => 'Aplicativo do promotor', 'ajuda' => 'Comportamento do app instalado no celular.'],
        'seguranca' => ['rotulo' => 'Segurança e aparelhos', 'ajuda' => 'Troca de celular e integridade do aparelho.'],
        'xp' => ['rotulo' => 'Régua de produtividade', 'ajuda' => 'Pontuação dos promotores, liga e avaliação do gestor (recurso do plano).'],
        'avancado' => ['rotulo' => 'Avançado (suporte)', 'ajuda' => 'Parâmetros sem tela própria.'],
    ];

    /** @var array<string, array<string, mixed>> */
    public const ITENS = [
        'CHECKIN_RAIO_METROS' => [
            'area' => 'visitas', 'rotulo' => 'Raio do check-in', 'tipo' => 'numero_ou_sem_limite', 'unidade' => 'm', 'min' => 10, 'max' => 5000,
            'ajuda' => 'Distância máxima da loja para o promotor iniciar a visita. "Sem limite" aceita check-in de qualquer lugar.',
            'ativo_significa' => 'sem_limite',
        ],
        'ATRASO_TOLERANCIA_MINUTOS' => [
            'area' => 'visitas', 'rotulo' => 'Tolerância de atraso', 'tipo' => 'numero', 'unidade' => 'min', 'min' => 0, 'max' => 240,
            'ajuda' => 'Quanto o promotor pode passar do horário antes de aparecer como atrasado na Operação do dia.',
        ],
        'JORNADA_INICIO' => [
            'area' => 'visitas', 'rotulo' => 'Jornada — início', 'tipo' => 'hora', 'grupo' => 'jornada',
            'ajuda' => 'Início do expediente; vale para a Operação do dia e para o rastreamento.',
        ],
        'JORNADA_FIM' => [
            'area' => 'visitas', 'rotulo' => 'Jornada — fim', 'tipo' => 'hora', 'grupo' => 'jornada',
            'ajuda' => 'Fim do expediente; precisa ser depois do início.',
        ],
        'VISITA_CANCELAMENTO_PERMITIDO' => [
            'area' => 'visitas', 'rotulo' => 'Promotor pode cancelar a própria visita', 'tipo' => 'bool',
            'ajuda' => 'Permite cancelar uma visita em andamento pelo aplicativo.',
        ],
        'REGISTRO_CANCELAMENTO_PERMITIDO' => [
            'area' => 'visitas', 'rotulo' => 'Promotor pode cancelar o próprio registro', 'tipo' => 'bool',
            'ajuda' => 'Permite cancelar um registro feito na visita. O registro continua guardado como cancelado.',
        ],
        'DIRECIONAMENTO_BLOQUEIA_CHECKOUT' => [
            'area' => 'visitas', 'rotulo' => 'Bloquear checkout com formulário pendente', 'tipo' => 'bool',
            'ajuda' => 'Ligado: o promotor não finaliza a visita com formulário de direcionamento pendente. Desligado: só avisa.',
        ],

        'AGENDA_REQUER_APROVACAO' => [
            'area' => 'agenda', 'rotulo' => 'Agenda do promotor passa por aprovação', 'tipo' => 'bool',
            'ajuda' => 'Ligado: o que o promotor agenda sozinho espera a decisão do gestor. Desligado: ele é autônomo.',
        ],
        'PONTOS_VENDA_RESTRITO_A_VINCULO' => [
            'area' => 'agenda', 'rotulo' => 'Promotor vê só as lojas vinculadas a ele', 'tipo' => 'bool',
            'ajuda' => 'Ligado: cada promotor enxerga apenas as lojas do próprio vínculo. Desligado: vê todas da empresa.',
        ],
        'SORTIMENTO_AUTONOMIA_PROMOTOR' => [
            'area' => 'agenda', 'rotulo' => 'Vincular produto ao sortimento da loja', 'tipo' => 'lista', 'opcoes' => self::AUTONOMIA,
            'ajuda' => 'O que o promotor pode fazer ao colocar um produto já existente no sortimento de uma loja.',
        ],
        'CATALOGO_AUTONOMIA_PROMOTOR' => [
            'area' => 'agenda', 'rotulo' => 'Cadastrar produto novo no catálogo', 'tipo' => 'lista', 'opcoes' => self::AUTONOMIA,
            'ajuda' => 'O que o promotor pode fazer ao cadastrar um produto que ainda não existe.',
        ],

        'RASTREAMENTO_INTERVALO_SEGUNDOS' => [
            'area' => 'rastreamento', 'rotulo' => 'Enviar posição a cada', 'tipo' => 'numero_ou_zero', 'unidade' => 's', 'min' => 30, 'max' => 3600,
            'ajuda' => '0 desliga o rastreamento. Quanto menor o intervalo, mais bateria o celular gasta; 60 é um bom meio-termo.',
        ],
        'RASTREAMENTO_EXIGENCIA' => [
            'area' => 'rastreamento', 'rotulo' => 'O promotor pode desligar?', 'tipo' => 'lista', 'opcoes' => [
                'OPCIONAL' => 'Opcional — o promotor pode recusar ou pausar',
                'AVISO' => 'Aviso — o app avisa, mas não trava',
                'OBRIGATORIO' => 'Obrigatório — sem localização o app fica bloqueado',
            ],
            'ajuda' => 'Quanto o app exige do promotor para manter a localização ligada.',
            'depende_de' => ['chave' => 'RASTREAMENTO_INTERVALO_SEGUNDOS', 'motivo' => 'Ligue o rastreamento para ajustar.'],
        ],
        'RASTREAMENTO_SO_NA_JORNADA' => [
            'area' => 'rastreamento', 'rotulo' => 'Cobrar só durante o expediente', 'tipo' => 'bool',
            'ajuda' => 'Fora da jornada o app não cobra nada e o rastreamento desliga.',
            'depende_de' => ['chave' => 'RASTREAMENTO_INTERVALO_SEGUNDOS', 'motivo' => 'Ligue o rastreamento para ajustar.'],
        ],
        'RASTREAMENTO_PAINEL_CONFORMIDADE' => [
            'area' => 'rastreamento', 'rotulo' => 'Mostrar no Mapa ao vivo quem está irregular', 'tipo' => 'bool',
            'ajuda' => 'Lista os promotores sem permissão, com GPS desligado ou sem sinal, e o motivo.',
            'depende_de' => ['chave' => 'RASTREAMENTO_INTERVALO_SEGUNDOS', 'motivo' => 'Ligue o rastreamento para ajustar.'],
        ],
        'RASTREAMENTO_HISTORICO_DIAS' => [
            'area' => 'rastreamento', 'rotulo' => 'Guardar o trajeto por', 'tipo' => 'numero', 'unidade' => 'dias', 'min' => 1, 'max' => 365,
            'ajuda' => 'Por quantos dias as posições ficam para a Rota do dia; o mais antigo é apagado todo dia.',
            'depende_de' => ['chave' => 'RASTREAMENTO_INTERVALO_SEGUNDOS', 'motivo' => 'Ligue o rastreamento para ajustar.'],
        ],
        'RASTREAMENTO_PARADA_MINUTOS' => [
            'area' => 'rastreamento', 'rotulo' => 'Parado fora de loja a partir de', 'tipo' => 'numero', 'unidade' => 'min', 'min' => 5, 'max' => 240,
            'ajuda' => 'Parado no mesmo lugar, sem loja por perto, por este tempo conta como parada fora de loja.',
            'depende_de' => ['chave' => 'RASTREAMENTO_INTERVALO_SEGUNDOS', 'motivo' => 'Ligue o rastreamento para ajustar.'],
        ],
        'RASTREAMENTO_AFASTAMENTO_METROS' => [
            'area' => 'rastreamento', 'rotulo' => 'Saiu da loja: distância', 'tipo' => 'numero', 'unidade' => 'm', 'min' => 50, 'max' => 5000,
            'ajuda' => 'Distância da loja, durante a visita, a partir da qual conta como "saiu da loja". Deixe acima do raio do check-in.',
            'depende_de' => ['chave' => 'RASTREAMENTO_INTERVALO_SEGUNDOS', 'motivo' => 'Ligue o rastreamento para ajustar.'],
        ],
        'RASTREAMENTO_AFASTAMENTO_MINUTOS' => [
            'area' => 'rastreamento', 'rotulo' => 'Saiu da loja: por pelo menos', 'tipo' => 'numero', 'unidade' => 'min', 'min' => 1, 'max' => 120,
            'ajuda' => 'Tempo longe da loja para contar como afastamento; um salto de GPS de um instante não conta.',
            'depende_de' => ['chave' => 'RASTREAMENTO_INTERVALO_SEGUNDOS', 'motivo' => 'Ligue o rastreamento para ajustar.'],
        ],
        'RASTREAMENTO_ROTA_RECORTE' => [
            'area' => 'rastreamento', 'rotulo' => 'A Rota do dia mostra', 'tipo' => 'lista', 'opcoes' => [
                'DIA_INTEIRO' => 'O dia inteiro, de 00:00 a 23:59',
                'EXPEDIENTE' => 'Só o expediente; o resto fica recolhido e o gestor abre se quiser',
                'SO_EXPEDIENTE' => 'Só o expediente; fora dele nunca aparece',
            ],
            'ajuda' => 'O expediente é o horário da jornada (início e fim) configurado acima.',
            'depende_de' => ['chave' => 'RASTREAMENTO_INTERVALO_SEGUNDOS', 'motivo' => 'Ligue o rastreamento para ajustar.'],
        ],
        'RASTREAMENTO_DESVIO_RAIO_METROS' => [
            'area' => 'rastreamento', 'rotulo' => 'Fora da área do roteiro: distância', 'tipo' => 'numero_ou_zero', 'unidade' => 'm', 'min' => 500, 'max' => 50000,
            'ajuda' => '0 desliga. Longe disso de todas as lojas do dia (as da agenda e as visitadas), no expediente, o trajeto aparece como fora da área do roteiro.',
            'depende_de' => ['chave' => 'RASTREAMENTO_INTERVALO_SEGUNDOS', 'motivo' => 'Ligue o rastreamento para ajustar.'],
        ],
        'RASTREAMENTO_DESVIO_MINUTOS' => [
            'area' => 'rastreamento', 'rotulo' => 'Fora da área do roteiro: por pelo menos', 'tipo' => 'numero', 'unidade' => 'min', 'min' => 1, 'max' => 240,
            'ajuda' => 'Tempo seguido fora da área para contar como desvio.',
            'depende_de' => ['chave' => 'RASTREAMENTO_DESVIO_RAIO_METROS', 'motivo' => 'Defina a distância para ajustar.'],
        ],

        'FATURAMENTO_HABILITADO' => [
            'area' => 'pedidos', 'rotulo' => 'Mostrar faturamento e Esforço × Retorno', 'tipo' => 'bool',
            // Recurso do plano: travado até o SUPERADMIN liberar a integração (empresas.faturamento_contratado).
            'exige_contrato' => [
                'campo' => 'faturamento_contratado',
                'motivo' => 'Recurso do plano: a integração de faturamento precisa ser contratada. Fale com o suporte para liberar.',
            ],
            'ajuda' => 'Liga valor, lucro e faturamento dos pedidos do ERP e o relatório Esforço × Retorno. Só aparece para quem tem a permissão "Ver faturamento" no perfil; o promotor nunca vê.',
        ],
        // Régua de XP (docs/88): só aparece pra empresa com o recurso de plano ligado (`so_com`).
        'XP_VISIVEL_PROMOTOR' => [
            'area' => 'xp', 'rotulo' => 'Promotor vê a pontuação', 'tipo' => 'bool', 'so_com' => 'controle_xp_habilitado',
            'ajuda' => 'Desligado é o modo prévia: a régua calcula e só o gestor vê. Ligue depois de calibrar classes e tolerâncias com o piloto.',
        ],
        'XP_PENDENTE_VISIVEL_PROMOTOR' => [
            'area' => 'xp', 'rotulo' => 'Mostrar XP aguardando avaliação', 'tipo' => 'bool', 'so_com' => 'controle_xp_habilitado',
            'ajuda' => 'Ligado: o promotor vê "+50 XP aguardando avaliação". Desligado: só vê depois que o gestor aprovar.',
        ],
        'XP_RANKING_HABILITADO' => [
            'area' => 'xp', 'rotulo' => 'Liga semanal da equipe', 'tipo' => 'bool', 'so_com' => 'controle_xp_habilitado',
            'ajuda' => 'Ranking da semana entre os promotores da mesma equipe. XP pessoal, meta e ofensiva aparecem mesmo com a liga desligada.',
        ],
        'XP_COMPETENCIA' => [
            'area' => 'xp', 'rotulo' => 'XP aprovado conta no dia', 'tipo' => 'lista', 'so_com' => 'controle_xp_habilitado',
            'opcoes' => ['APROVACAO' => 'Da aprovação — o dia nunca muda depois de passado', 'REGISTRO' => 'Do registro — o histórico mostra quando o trabalho foi feito'],
            'ajuda' => 'Semana da liga já fechada não muda, mesmo que um XP seja aprovado depois.',
        ],
        'XP_TOLERANCIA_DURACAO_PERCENTUAL' => [
            'area' => 'xp', 'rotulo' => 'Tolerância de duração', 'tipo' => 'numero', 'unidade' => '%', 'min' => 0, 'max' => 300, 'so_com' => 'controle_xp_habilitado',
            'ajuda' => 'Quanto a visita pode passar da duração prevista sem perder o XP de tempo. A tolerância de início é a mesma "Tolerância de atraso" das visitas.',
        ],
        'XP_TEMPO_MINIMO_PERCENTUAL' => [
            'area' => 'xp', 'rotulo' => 'Tempo mínimo para pontuar', 'tipo' => 'numero', 'unidade' => '%', 'min' => 0, 'max' => 100, 'so_com' => 'controle_xp_habilitado',
            'ajuda' => 'Visita abaixo deste percentual da duração prevista não pontua nada.',
        ],
        'XP_LIGA_MINIMO_VISITAS' => [
            'area' => 'xp', 'rotulo' => 'Mínimo de visitas para entrar na liga', 'tipo' => 'numero', 'unidade' => 'visitas', 'min' => 0, 'max' => 100, 'so_com' => 'controle_xp_habilitado',
            'ajuda' => 'Visitas agendadas na semana. Quem tiver menos (férias, afastamento) fica fora da semana.',
        ],
        // Composição do XP da visita (docs/88 §5.1): os 6 pesos somam 100 (LoteParametrosRequest).
        'XP_PESO_PRESENCA' => [
            'area' => 'xp', 'rotulo' => 'Peso: Presença no raio', 'tipo' => 'numero', 'unidade' => '%', 'min' => 0, 'max' => 100, 'grupo' => 'composicao_xp', 'so_com' => 'controle_xp_habilitado',
            'ajuda' => 'Parte do XP da visita ganha com check-in dentro do raio da loja. Os 6 pesos somam 100%; a mudança vale pras OS criadas daqui pra frente.',
        ],
        'XP_PESO_COMPLETUDE' => [
            'area' => 'xp', 'rotulo' => 'Peso: Obrigatórias', 'tipo' => 'numero', 'unidade' => '%', 'min' => 0, 'max' => 100, 'grupo' => 'composicao_xp', 'so_com' => 'controle_xp_habilitado',
            'ajuda' => 'Parte do XP da visita ganha com as obrigatórias feitas (proporcional). Os 6 pesos somam 100%; a mudança vale pras OS criadas daqui pra frente.',
        ],
        'XP_PESO_QUALIDADE' => [
            'area' => 'xp', 'rotulo' => 'Peso: Qualidade', 'tipo' => 'numero', 'unidade' => '%', 'min' => 0, 'max' => 100, 'grupo' => 'composicao_xp', 'so_com' => 'controle_xp_habilitado',
            'ajuda' => 'Parte do XP da visita ganha com a nota dos formulários com pontuação (ruptura fica fora). Os 6 pesos somam 100%; a mudança vale pras OS criadas daqui pra frente.',
        ],
        'XP_PESO_PONTUALIDADE' => [
            'area' => 'xp', 'rotulo' => 'Peso: Início no horário', 'tipo' => 'numero', 'unidade' => '%', 'min' => 0, 'max' => 100, 'grupo' => 'composicao_xp', 'so_com' => 'controle_xp_habilitado',
            'ajuda' => 'Parte do XP da visita ganha com início dentro da tolerância de atraso. Os 6 pesos somam 100%; a mudança vale pras OS criadas daqui pra frente.',
        ],
        'XP_PESO_TEMPO' => [
            'area' => 'xp', 'rotulo' => 'Peso: Tempo na loja', 'tipo' => 'numero', 'unidade' => '%', 'min' => 0, 'max' => 100, 'grupo' => 'composicao_xp', 'so_com' => 'controle_xp_habilitado',
            'ajuda' => 'Parte do XP da visita ganha com duração dentro da tolerância. Os 6 pesos somam 100%; a mudança vale pras OS criadas daqui pra frente.',
        ],
        'XP_PESO_ENVIO' => [
            'area' => 'xp', 'rotulo' => 'Peso: Envio no mesmo dia', 'tipo' => 'numero', 'unidade' => '%', 'min' => 0, 'max' => 100, 'grupo' => 'composicao_xp', 'so_com' => 'controle_xp_habilitado',
            'ajuda' => 'Parte do XP da visita ganha com check-out recebido no mesmo dia. Os 6 pesos somam 100%; a mudança vale pras OS criadas daqui pra frente.',
        ],
        'AVALIACAO_FORMULARIO_HABILITADA' => [
            'area' => 'visitas', 'rotulo' => 'Avaliação de formulário pelo gestor', 'tipo' => 'bool',
            'ajuda' => 'Liga a fila de avaliação. Em cada formulário, marque "Exige avaliação" para os que o gestor precisa aprovar.',
        ],
        'PEDIDO_VENDA_SEM_VISITA_PERMITIDO' => [
            'area' => 'pedidos', 'rotulo' => 'Pedido de venda fora de uma visita', 'tipo' => 'bool',
            'ajuda' => 'Ligado: o vendedor pode tirar pedido sem estar em visita. Desligado: só durante a visita.',
        ],

        'CODIGO_BARRAS_OBRIGATORIO' => [
            'area' => 'catalogo', 'rotulo' => 'Código de barras obrigatório', 'tipo' => 'bool',
            'ajuda' => 'Exige o código de barras ao cadastrar um produto.',
        ],
        'CODIGO_BARRAS_UNICO' => [
            'area' => 'catalogo', 'rotulo' => 'Código de barras único', 'tipo' => 'bool',
            'ajuda' => 'Não deixa dois produtos da empresa com o mesmo código de barras.',
        ],

        'ATIVIDADES_POLLING_SEGUNDOS' => [
            'area' => 'paineis', 'rotulo' => 'Atualizar o Painel de Atividades a cada', 'tipo' => 'numero', 'unidade' => 's', 'min' => 10, 'max' => 600,
            'ajuda' => 'Intervalo da atualização automática do painel.',
        ],
        'ATIVIDADES_ALERTA_REQUER_RESOLUCAO' => [
            'area' => 'paineis', 'rotulo' => 'Alertas ficam pendentes até alguém resolver', 'tipo' => 'bool',
            'ajuda' => 'Ligado: o alerta só sai da lista quando alguém resolve (com motivo). Desligado: é só informativo.',
        ],
        'CONTRATO_AVISO_DIAS' => [
            'area' => 'paineis', 'rotulo' => 'Avisar contrato vencendo com', 'tipo' => 'numero', 'unidade' => 'dias', 'min' => 1, 'max' => 365,
            'ajuda' => 'Quantos dias antes do vencimento o contrato aparece como "vencendo".',
        ],
        'PLANEJADOR_VISITAS_OMITIR_DOMINGO' => [
            'area' => 'paineis', 'rotulo' => 'Ocultar domingo no Planejador e na Rota', 'tipo' => 'bool', 'grupo' => 'dias_ocultos',
            'ajuda' => 'Esconde o domingo no Planejador de visitas e no relatório de rota.',
        ],
        'PLANEJADOR_VISITAS_OMITIR_SABADO' => [
            'area' => 'paineis', 'rotulo' => 'Ocultar sábado no Planejador e na Rota', 'tipo' => 'bool', 'grupo' => 'dias_ocultos',
            'ajuda' => 'Esconde o sábado no Planejador de visitas e no relatório de rota.',
        ],

        'SYNC_INTERVALO_HORAS' => [
            'area' => 'aplicativo', 'rotulo' => 'Sincronização silenciosa a cada', 'tipo' => 'numero', 'unidade' => 'h', 'min' => 1, 'max' => 24,
            'ajuda' => 'De quanto em quanto tempo o app atualiza os dados sozinho.',
        ],

        'TROCA_APARELHO_DIAS' => [
            'area' => 'seguranca', 'rotulo' => 'Dias entre trocas de celular', 'tipo' => 'numero', 'unidade' => 'dias', 'min' => 7, 'max' => 90,
            'ajuda' => 'Intervalo mínimo entre trocas de celular que o próprio usuário faz no app; nunca abaixo de 7.',
        ],
        'TROCA_APARELHO_EXIGE_APROVACAO' => [
            'area' => 'seguranca', 'rotulo' => 'Toda troca de celular precisa de aprovação', 'tipo' => 'bool',
            'ajuda' => 'Ligado: o ADMIN libera cada troca. Desligado: 1 troca por janela, sem aprovação.',
        ],
        'TROCA_APARELHO_SO_REGISTRA' => [
            'area' => 'seguranca', 'rotulo' => 'Período de medição (só registrar)', 'tipo' => 'bool',
            'ajuda' => 'A troca nunca é bloqueada; o sistema só registra o que teria sido bloqueado.',
        ],
        'INTEGRIDADE_MODO' => [
            'area' => 'seguranca', 'rotulo' => 'Aparelho com root ou emulador', 'tipo' => 'lista', 'opcoes' => [
                'ALERTA' => 'Alerta — entra e avisa o ADMIN',
                'BLOQUEIO' => 'Bloqueio — o login é negado',
            ],
            'ajuda' => 'O que acontece quando o aparelho não passa na checagem de integridade.',
        ],
    ];

    public const AUTONOMIA = [
        'DESABILITADO' => 'Desabilitado — a opção some do app',
        'AUTONOMO' => 'Autônomo — faz direto, sem aprovação',
        'REQUER_APROVACAO' => 'Requer aprovação — faz, mas fica pendente até o gestor decidir',
    ];

    /**
     * Metadados da chave; chave sem entrada vira "avancado" com a descrição técnica.
     *
     * @return array<string, mixed>
     */
    public static function de(string $chave): array
    {
        $descricao = ParametrosPadrao::CATALOGO[$chave]['descricao'] ?? '';

        return (self::ITENS[$chave] ?? [
            'area' => 'avancado', 'rotulo' => $chave, 'tipo' => 'texto', 'ajuda' => $descricao,
        ]) + ['descricao_tecnica' => $descricao];
    }
}

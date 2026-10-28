<?php

namespace App\Support;

/**
 * Referência modelo a modelo da página pública /docs/integracao (docs/96): cada campo com tipo,
 * se aparece na leitura, se é aceito na escrita e o exemplo. O exemplo JSON de cada modelo é
 * montado daqui, então a tabela e o exemplo nunca divergem.
 *
 * Ao mudar um campo em Integracao\LeituraController ou nos importadores, mude aqui também.
 *
 * Campo: [nome, tipo, descrição, flags, exemplo]. Flags: L = vem na leitura, E = aceito na
 * escrita, C = obrigatório ao criar, K = chave do upsert.
 */
class DocumentacaoIntegracao
{
    public static function modelos(): array
    {
        $uuid = '9b1f6c2e-4a7d-4e2b-8f0a-3c5d7e9a1b2c';
        $alterado = ['alterado_em', 'data-hora', 'Última alteração. Use como marca na leitura incremental.', 'L', '2026-10-22T13:05:00Z'];

        return [
            [
                'id' => 'modelo-loja', 'nome' => 'Loja', 'descricao' => 'Ponto de venda (PDV) que os promotores visitam.',
                'ler' => ['GET /lojas', 'lojas:ler', 'cadastro'], 'escrever' => ['PUT /lojas', 'lojas:escrever'],
                'campos' => [
                    ['uuid', 'uuid', 'Identificador no Horus.', 'L', $uuid],
                    ['codigo_externo', 'texto', 'Código da loja no ERP.', 'LEK', 'L1'],
                    ['cnpj', 'texto', 'Só números ou com máscara.', 'LE', '12345678000190'],
                    ['razao_social', 'texto', '', 'LEC', 'Mercado Bom Preço LTDA'],
                    ['fantasia', 'texto', 'Nome que aparece no app.', 'LEC', 'Bom Preço'],
                    ['endereco', 'texto', '', 'LEC', 'Av. Brasil'],
                    ['numero', 'texto', '', 'LE', '100'],
                    ['bairro', 'texto', '', 'LE', 'Centro'],
                    ['cidade', 'texto', '', 'LEC', 'Manaus'],
                    ['uf', 'texto', 'Sigla com 2 letras.', 'LE', 'AM'],
                    ['cep', 'texto', '', 'LE', '69000000'],
                    ['telefone', 'texto', '', 'LE', '9233334444'],
                    ['email', 'texto', '', 'LE', 'contato@bompreco.com.br'],
                    ['latitude', 'decimal', 'Usada no check-in por GPS.', 'LEC', -3.1019],
                    ['longitude', 'decimal', '', 'LEC', -60.025],
                    ['rede', 'texto', 'Nome da rede (ver Rede). Criada se não existir.', 'LE', 'Atacadão'],
                    ['ramo_atividade', 'texto', 'Nome do ramo (ver Ramo de atividade).', 'LE', 'Supermercado'],
                    ['numero_checkouts', 'inteiro', '', 'LE', 8],
                    ['vendedores', 'lista de texto', 'Códigos dos vendedores da carteira da loja. Na escrita, substitui a carteira; ausente não mexe.', 'LE', ['374', '680']],
                    ['ativo', 'booleano', '', 'LE', true],
                    $alterado,
                ],
            ],
            [
                'id' => 'modelo-produto', 'nome' => 'Produto', 'descricao' => 'Item do catálogo auditado nas visitas.',
                'ler' => ['GET /produtos', 'produtos:ler', 'cadastro'], 'escrever' => ['PUT /produtos', 'produtos:escrever'],
                'campos' => [
                    ['uuid', 'uuid', '', 'L', $uuid],
                    ['codigo_externo', 'texto', 'Código do produto no ERP.', 'LEK', 'P1'],
                    ['descricao', 'texto', '', 'LEC', 'Refrigerante Cola 2L'],
                    ['codigo_barras', 'texto', 'EAN/GTIN.', 'LE', '7891234567890'],
                    ['marca', 'texto', 'Nome da marca (ver Marca).', 'LE', 'Marca Própria'],
                    ['departamento', 'texto', 'Nome do departamento.', 'LE', 'Bebidas'],
                    ['secao', 'texto', 'Nome da seção, dentro do departamento.', 'LE', 'Refrigerantes'],
                    ['peso_kg', 'decimal', '', 'E', 2.1],
                    ['propriedade', 'enum', '`PROPRIA` ou `CONCORRENTE`. Padrão na criação: `PROPRIA`.', 'E', 'PROPRIA'],
                    ['preco_tabela', 'decimal', '', 'LE', 9.9],
                    ['desconto_maximo_pct', 'decimal', 'Desconto máximo no pedido de venda, em %.', 'E', 10],
                    ['ativo', 'booleano', '', 'LE', true],
                    $alterado,
                ],
            ],
            [
                'id' => 'modelo-mix', 'nome' => 'Mix da loja', 'descricao' => 'O que cada loja deve ter na gôndola (sortimento).',
                'ler' => ['GET /mix', 'mix:ler', 'cadastro'], 'escrever' => ['PUT /mix', 'mix:escrever'],
                'nota' => 'Na escrita, cada item é um par loja × produto e soma ao mix. Com `"replace": true` no corpo (ao lado de `items`), cada loja do lote fica só com o que veio. Na leitura, um item pode ser um produto ou um departamento, seção ou marca inteiros (`tipo_item`); item removido some da lista.',
                'campos' => [
                    ['uuid', 'uuid', '', 'L', $uuid],
                    ['loja_uuid', 'uuid', '', 'L', $uuid],
                    ['codigo_externo_loja', 'texto', 'Na leitura vem como `loja_codigo_externo`.', 'EKC', 'L1'],
                    ['tipo_item', 'enum', '`PRODUTO`, `SECAO`, `DEPARTAMENTO` ou `MARCA`.', 'L', 'PRODUTO'],
                    ['produto_uuid', 'uuid', '', 'L', $uuid],
                    ['codigo_externo_produto', 'texto', 'Na leitura vem como `produto_codigo_externo`.', 'EKC', 'P1'],
                    ['departamento', 'texto', 'Quando `tipo_item` é DEPARTAMENTO.', 'L', null],
                    ['secao', 'texto', 'Quando `tipo_item` é SECAO.', 'L', null],
                    ['marca', 'texto', 'Quando `tipo_item` é MARCA.', 'L', null],
                    ['status_aprovacao', 'enum', '`null` = valendo; `PENDENTE` ou `REJEITADO` = sugestão do promotor.', 'L', null],
                    $alterado,
                ],
            ],
            [
                'id' => 'modelo-supervisor', 'nome' => 'Supervisor', 'descricao' => 'Supervisor da equipe comercial do ERP.',
                'ler' => ['GET /supervisores', 'vendedores:ler', 'cadastro'], 'escrever' => ['PUT /supervisores', 'vendedores:escrever'],
                'campos' => [
                    ['uuid', 'uuid', '', 'L', $uuid],
                    ['codigo_externo', 'texto', 'Código no ERP.', 'LEK', 'S1'],
                    ['nome', 'texto', '', 'LEC', 'Carlos Supervisor'],
                    ['telefone', 'texto', '', 'LE', '92999990000'],
                    ['email', 'texto', '', 'LE', 'carlos@empresa.com.br'],
                    ['ativo', 'booleano', '', 'LE', true],
                    $alterado,
                ],
            ],
            [
                'id' => 'modelo-vendedor', 'nome' => 'Vendedor', 'descricao' => 'Vendedor (RCA) do ERP.',
                'ler' => ['GET /vendedores', 'vendedores:ler', 'cadastro'], 'escrever' => ['PUT /vendedores', 'vendedores:escrever'],
                'campos' => [
                    ['uuid', 'uuid', '', 'L', $uuid],
                    ['codigo_externo', 'texto', 'Código no ERP.', 'LEK', '374'],
                    ['nome', 'texto', '', 'LEC', 'Diemy Vendas'],
                    ['telefone', 'texto', '', 'LE', '92988887777'],
                    ['email', 'texto', '', 'LE', 'diemy@empresa.com.br'],
                    ['codigo_supervisor', 'texto', 'Código do supervisor (envie o supervisor antes).', 'LE', 'S1'],
                    ['ativo', 'booleano', '', 'LE', true],
                    $alterado,
                ],
            ],
            [
                'id' => 'modelo-carteira', 'nome' => 'Carteira', 'descricao' => 'Quais vendedores atendem cada loja (um par por linha).',
                'ler' => ['GET /carteira', 'vendedores:ler', null], 'escrever' => ['PUT /carteira', 'vendedores:escrever'],
                'nota' => 'Na escrita, cada loja do lote fica exatamente com os vendedores listados para ela. Na leitura, um vínculo removido some; para conferir a carteira inteira, leia sem `updated_since`.',
                'campos' => [
                    ['loja_uuid', 'uuid', '', 'L', $uuid],
                    ['codigo_externo_loja', 'texto', 'Na leitura vem como `loja_codigo_externo`.', 'EKC', 'L1'],
                    ['codigo_externo_vendedor', 'texto', 'Na leitura vem como `vendedor_codigo_externo`.', 'EKC', '374'],
                    $alterado,
                ],
            ],
            [
                'id' => 'modelo-promotor', 'nome' => 'Promotor', 'descricao' => 'Usuário do app (promotor). A leitura traz todos os usuários da empresa, só com identificação.',
                'ler' => ['GET /usuarios', 'usuarios:ler', 'cadastro'], 'escrever' => ['PUT /promotores', 'usuarios:escrever'],
                'nota' => 'A leitura nunca traz CPF, e-mail, telefone ou localização (LGPD). Um promotor criado aqui ocupa uma licença; sem licença livre ele é criado sem acesso ao app e aparece em `details.sem_licenca`, até um administrador liberar.',
                'campos' => [
                    ['uuid', 'uuid', '', 'L', $uuid],
                    ['codigo_externo', 'texto', 'Matrícula ou código no ERP.', 'LEK', 'PR1'],
                    ['nome', 'texto', '', 'LEC', 'Ana Paula'],
                    ['email', 'texto', 'Login no app. Único em todo o Horus.', 'EC', 'ana.paula@empresa.com.br'],
                    ['senha_inicial', 'texto', 'Mínimo 8 caracteres. Só vale na criação; depois o promotor troca no app e a API nunca altera.', 'EC', 'provisoria1'],
                    ['tipo', 'enum', '`ADMIN`, `GESTOR` ou `PROMOTOR`.', 'L', 'PROMOTOR'],
                    ['codigo_supervisor', 'texto', 'Código do supervisor no ERP.', 'E', 'S1'],
                    ['supervisor_uuid', 'uuid', '', 'L', null],
                    ['ativo', 'booleano', '', 'LE', true],
                    $alterado,
                ],
            ],
            [
                'id' => 'modelo-pedido', 'nome' => 'Pedido', 'descricao' => 'Pedido do ERP, com itens, faturamento e entregas, para cruzar com as visitas.',
                'ler' => ['GET /pedidos', 'pedidos:ler', 'data_pedido'], 'escrever' => ['PUT /pedidos', 'pedidos:escrever'],
                'nota' => 'Até 100 pedidos por chamada. Reenviar o mesmo `numero_pedido` substitui o pedido inteiro, itens inclusive. Devolução pode vir com valores negativos.',
                'campos' => [
                    ['uuid', 'uuid', '', 'L', $uuid],
                    ['numero_pedido', 'texto', 'Número do pedido no ERP.', 'LEK', '1001'],
                    ['numero_nf', 'texto', '', 'LE', '55123'],
                    ['codigo_externo_loja', 'texto', 'Na leitura vem como `loja_codigo_externo`, junto de `loja_uuid`.', 'EC', 'L1'],
                    ['data_pedido', 'data', '', 'LEC', '2026-10-20'],
                    ['data_previsao_entrega', 'data', 'Reenviar com outra data = revisão da previsão.', 'LE', '2026-10-23'],
                    ['valor_total', 'decimal', '', 'LE', 150.5],
                    ['valor_lucro_total', 'decimal', '', 'LE', 32.1],
                    ['status_faturamento', 'enum', '`NAO_FATURADO`, `FATURADO`, `CANCELADO` ou `DEVOLVIDO`.', 'LE', 'FATURADO'],
                    ['faturado_em', 'data', '', 'LE', '2026-10-21'],
                    ['observacao', 'texto', 'Até 2000 caracteres.', 'LE', null],
                    ['itens', 'lista de Item do pedido', '1 a 500 itens.', 'LEC', [[
                        'codigo_externo_produto' => 'P1', 'descricao_produto' => 'Refrigerante Cola 2L', 'quantidade' => 10,
                        'preco_unitario' => 15.05, 'subtotal' => 150.5, 'custo_unitario' => 11.84, 'valor_lucro' => 32.1,
                    ]]],
                    ['entregas', 'lista de Entrega', 'Só na leitura; a escrita é por `PUT /entregas`.', 'L', [['data_entrega' => '2026-10-22T10:00:00Z', 'observacao' => null]]],
                    $alterado,
                ],
            ],
            [
                'id' => 'modelo-item-pedido', 'nome' => 'Item do pedido', 'descricao' => 'Linha de um pedido (dentro de `itens`).',
                'campos' => [
                    ['codigo_externo_produto', 'texto', 'Não precisa existir no catálogo; quando existe, a leitura traz `produto_uuid`.', 'LEC', 'P1'],
                    ['produto_uuid', 'uuid', '', 'L', $uuid],
                    ['descricao_produto', 'texto', '', 'LEC', 'Refrigerante Cola 2L'],
                    ['quantidade', 'decimal', 'Maior que zero.', 'LEC', 10],
                    ['preco_unitario', 'decimal', '', 'LE', 15.05],
                    ['subtotal', 'decimal', '', 'LE', 150.5],
                    ['custo_unitario', 'decimal', '', 'LE', 11.84],
                    ['valor_lucro', 'decimal', '', 'LE', 32.1],
                ],
            ],
            [
                'id' => 'modelo-entrega', 'nome' => 'Entrega', 'descricao' => 'Entrega de um pedido na loja.',
                'escrever' => ['PUT /entregas', 'pedidos:escrever'],
                'nota' => 'Até 500 por chamada. Reenviar o mesmo pedido + horário não duplica. Na leitura, as entregas vêm dentro do Pedido.',
                'campos' => [
                    ['numero_pedido', 'texto', 'O pedido precisa já ter sido enviado.', 'EKC', '1001'],
                    ['data_entrega', 'data-hora', '', 'LEKC', '2026-10-22T10:00:00Z'],
                    ['observacao', 'texto', '', 'LE', 'Recebido pelo gerente'],
                ],
            ],
            [
                'id' => 'modelo-apoio', 'nome' => 'Rede, ramo, marca, departamento e seção', 'descricao' => 'Cadastros de apoio, só de escrita. Lojas e produtos apontam para eles pelo nome.',
                'escrever' => ['PUT /redes · /ramos-atividade · /marcas · /departamentos · /secoes', 'lojas:escrever (redes, ramos) · produtos:escrever (o resto)'],
                'nota' => 'O nome é a chave: maiúsculas, acentos e espaços nas pontas não diferenciam (`"ATACADÃO"` = `"atacadão"`). Seção usa departamento + nome; `propriedade` só existe em marca.',
                'campos' => [
                    ['descricao', 'texto', 'Nome.', 'EKC', 'Atacadão'],
                    ['departamento', 'texto', 'Só em seções: nome do departamento.', 'EC', null],
                    ['propriedade', 'enum', 'Só em marcas: `PROPRIA` ou `CONCORRENTE`.', 'E', null],
                    ['ativo', 'booleano', '', 'E', true],
                ],
            ],
            [
                'id' => 'modelo-visita', 'nome' => 'Visita', 'descricao' => 'Ida de um promotor a uma loja, do check-in ao check-out.',
                'ler' => ['GET /visitas', 'visitas:ler', 'inicio'],
                'campos' => [
                    ['uuid', 'uuid', '', 'L', $uuid],
                    ['loja_uuid', 'uuid', '', 'L', $uuid],
                    ['promotor_uuid', 'uuid', '', 'L', $uuid],
                    ['ordem_servico_uuid', 'uuid', 'Agenda que originou a visita, se houver.', 'L', $uuid],
                    ['status', 'enum', '`ABERTA`, `FINALIZADA` ou `CANCELADA`.', 'L', 'FINALIZADA'],
                    ['inicio', 'data-hora', 'Check-in.', 'L', '2026-10-22T12:20:00Z'],
                    ['fim', 'data-hora', 'Check-out.', 'L', '2026-10-22T13:05:00Z'],
                    ['inicio_distancia_metros', 'inteiro', 'Distância da loja no check-in.', 'L', 18],
                    ['fim_distancia_metros', 'inteiro', 'Distância da loja no check-out.', 'L', 22],
                    ['checkout_tipo', 'enum', 'Quem fechou: `PROMOTOR`, `ADMIN`, `SAIDA_DETECTADA` ou `AUTOMATICO`.', 'L', 'PROMOTOR'],
                    ['afastamento_qtd', 'inteiro', 'Quantas vezes saiu da loja durante a visita.', 'L', 0],
                    ['afastamento_minutos', 'inteiro', 'Tempo total fora da loja durante a visita.', 'L', 0],
                    ['obrigatorias_feitas', 'inteiro', 'Tarefas obrigatórias concluídas.', 'L', 5],
                    ['obrigatorias_total', 'inteiro', '', 'L', 5],
                    $alterado,
                ],
            ],
            [
                'id' => 'modelo-registro', 'nome' => 'Registro', 'descricao' => 'O que o promotor registrou na visita: foto, preço, validade, ruptura etc.',
                'ler' => ['GET /registros', 'registros:ler', 'criação'],
                'campos' => [
                    ['uuid', 'uuid', '', 'L', $uuid],
                    ['visita_uuid', 'uuid', '', 'L', $uuid],
                    ['loja_uuid', 'uuid', '', 'L', $uuid],
                    ['promotor_uuid', 'uuid', '', 'L', $uuid],
                    ['produto_uuid', 'uuid', '', 'L', $uuid],
                    ['produto', 'texto', 'Descrição do produto.', 'L', 'Refrigerante Cola 2L'],
                    ['ruptura', 'booleano', 'Produto em falta.', 'L', false],
                    ['tipo_registro', 'texto', 'Nome do tipo, configurado pela empresa.', 'L', 'Preço'],
                    ['observacao', 'texto', '', 'L', null],
                    ['campos', 'objeto', 'Respostas do formulário do tipo de registro, chave → valor.', 'L', ['preco' => '9.99']],
                    ['fotos', 'lista de objeto', '`{ uuid, url }`. A `url` é assinada, vale 30 minutos e não precisa de token.', 'L', [['uuid' => $uuid, 'url' => 'https://…/fotos/9b1f…?expires=…&signature=…']]],
                    ['criado_em', 'data-hora', '', 'L', '2026-10-22T12:31:00Z'],
                    ['cancelado_em', 'data-hora', 'Preenchido quando o registro foi cancelado.', 'L', null],
                    $alterado,
                ],
            ],
            [
                'id' => 'modelo-ruptura', 'nome' => 'Ruptura', 'descricao' => 'Registro com produto em falta, e a resolução.',
                'ler' => ['GET /rupturas', 'rupturas:ler', 'criação'],
                'campos' => [
                    ['uuid', 'uuid', '', 'L', $uuid],
                    ['visita_uuid', 'uuid', '', 'L', $uuid],
                    ['loja_uuid', 'uuid', '', 'L', $uuid],
                    ['promotor_uuid', 'uuid', '', 'L', $uuid],
                    ['produto_uuid', 'uuid', '', 'L', $uuid],
                    ['produto', 'texto', '', 'L', 'Refrigerante Cola 2L'],
                    ['ruptura', 'booleano', 'Sempre `true`.', 'L', true],
                    ['criado_em', 'data-hora', '', 'L', '2026-10-22T12:31:00Z'],
                    ['cancelado_em', 'data-hora', '', 'L', null],
                    ['resolvida_em', 'data-hora', '`null` enquanto aberta.', 'L', '2026-10-23T09:00:00Z'],
                    ['motivo_resolucao', 'texto', '', 'L', 'Reposto pelo estoque'],
                    $alterado,
                ],
            ],
            [
                'id' => 'modelo-ordem-servico', 'nome' => 'Ordem de serviço', 'descricao' => 'Visita prevista na agenda (o planejado), para comparar com a realizada.',
                'ler' => ['GET /ordens-servico', 'ordens_servico:ler', 'prazo_inicio'],
                'campos' => [
                    ['uuid', 'uuid', '', 'L', $uuid],
                    ['loja_uuid', 'uuid', '', 'L', $uuid],
                    ['promotor_uuid', 'uuid', '', 'L', $uuid],
                    ['visita_uuid', 'uuid', 'Visita que cumpriu a ordem, se houver.', 'L', $uuid],
                    ['origem', 'enum', '`MANUAL`, `CAMPANHA`, `AGENDA`, `CONTRATO` ou `DIRECIONAMENTO`.', 'L', 'AGENDA'],
                    ['status', 'enum', '`PENDENTE`, `EM_ANDAMENTO`, `CONCLUIDA`, `CANCELADA`, `AGUARDANDO_APROVACAO`, `REAGENDAMENTO_SOLICITADO` ou `CANCELAMENTO_SOLICITADO`.', 'L', 'CONCLUIDA'],
                    ['obrigatoria', 'booleano', '', 'L', true],
                    ['prioridade', 'enum', '`BAIXA`, `MEDIA` ou `ALTA`.', 'L', 'MEDIA'],
                    ['prazo_inicio', 'data-hora', '', 'L', '2026-10-22T03:00:00Z'],
                    ['prazo_fim', 'data-hora', '', 'L', '2026-10-23T02:59:59Z'],
                    ['horario_previsto', 'texto', '`HH:MM`, no fuso da empresa.', 'L', '08:30'],
                    ['duracao_prevista_minutos', 'inteiro', '', 'L', 40],
                    ['xp_previsto', 'inteiro', '', 'L', 10],
                    ['cancelada_em', 'data-hora', '', 'L', null],
                    $alterado,
                ],
            ],
            [
                'id' => 'modelo-xp', 'nome' => 'Lançamento de XP', 'descricao' => 'Pontos ganhos ou perdidos pelo promotor.',
                'ler' => ['GET /xp', 'xp:ler', 'competencia'],
                'nota' => 'Lançamento não é editado: correções entram como novo lançamento (`AJUSTE` ou `ESTORNO`). `updated_since` olha `criado_em` e `resolvido_em`.',
                'campos' => [
                    ['uuid', 'uuid', '', 'L', $uuid],
                    ['promotor_uuid', 'uuid', '', 'L', $uuid],
                    ['visita_uuid', 'uuid', '', 'L', $uuid],
                    ['ordem_servico_uuid', 'uuid', '', 'L', null],
                    ['natureza', 'enum', '`PLANO`, `INICIATIVA`, `PERDA`, `ESTORNO` ou `AJUSTE`.', 'L', 'PLANO'],
                    ['categoria', 'texto', 'Regra que gerou o lançamento.', 'L', 'VISITA'],
                    ['pontos', 'inteiro', 'Negativo nas perdas.', 'L', 10],
                    ['status', 'enum', '`OFICIAL`, `PENDENTE` ou `DESCARTADO`.', 'L', 'OFICIAL'],
                    ['competencia', 'data', 'Dia a que os pontos pertencem.', 'L', '2026-10-22'],
                    ['motivo', 'texto', '', 'L', null],
                    ['criado_em', 'data-hora', '', 'L', '2026-10-22T13:05:00Z'],
                    ['resolvido_em', 'data-hora', 'Quando saiu de PENDENTE.', 'L', null],
                ],
            ],
        ];
    }

    /** Exemplo JSON de um modelo, só com os campos do lado pedido (L ou E). */
    public static function exemplo(array $modelo, string $lado): string
    {
        $obj = [];
        foreach ($modelo['campos'] as [$nome, , , $flags, $exemplo]) {
            if (str_contains($flags, $lado)) {
                $obj[$nome] = $exemplo;
            }
        }

        return json_encode($obj, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** `trecho` em crase vira <code>, o resto é escapado. */
    public static function texto(string $texto): string
    {
        return preg_replace('/`([^`]+)`/', '<code>$1</code>', e($texto));
    }
}

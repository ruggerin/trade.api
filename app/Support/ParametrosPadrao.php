<?php

namespace App\Support;

use App\Models\Empresa;
use App\Models\Parametro;

/**
 * Catálogo dos parâmetros que o sistema lê, com o valor que cada um assume quando a linha não
 * existe — usado pra CADASTRAR explicitamente esses parâmetros numa empresa (provisionamento,
 * botão "Completar parâmetros" do superadmin, comando `parametros:completar`), pra que apareçam na
 * tela de Parâmetros da empresa e ela possa ajustar.
 *
 * Os valores são de propósito iguais ao fallback de cada Support (RaioCheckin, OperacaoDoDia,
 * DirecionamentoParametros...): cadastrar um parâmetro que faltava NÃO muda o comportamento da
 * empresa, só o torna visível/editável. A fonte de verdade do default continua em cada Support —
 * ao criar um parâmetro novo no código, acrescente-o aqui também.
 *
 * Nunca sobrescreve o que já existe, nem reativa parâmetro desativado (pra CHECKIN_RAIO_METROS,
 * desativado quer dizer "sem limite de raio" — ver RaioCheckin).
 */
final class ParametrosPadrao
{
    /** @var array<string, array{valor: string, descricao: string}> */
    public const CATALOGO = [
        'CHECKIN_RAIO_METROS' => ['valor' => '200', 'descricao' => 'Raio de check-in em metros — desativado = sem limite (App\Support\RaioCheckin)'],
        'CONTRATO_AVISO_DIAS' => ['valor' => '30', 'descricao' => 'Dias de antecedência pra avisar contrato vencendo (App\Support\AvisoVencimentoContrato)'],
        'SYNC_INTERVALO_HORAS' => ['valor' => '4', 'descricao' => 'Intervalo da sincronização silenciosa do app mobile, em horas'],
        'PONTOS_VENDA_RESTRITO_A_VINCULO' => ['valor' => 'false', 'descricao' => 'Modo restrito de visibilidade de PDV (App\Support\VisibilidadePontosVenda) — false = modo aberto'],
        'AGENDA_REQUER_APROVACAO' => ['valor' => 'false', 'descricao' => 'Autonomia do promotor sobre a própria agenda (App\Support\AutonomiaAgenda) — false = autônomo'],
        'REGISTRO_CANCELAMENTO_PERMITIDO' => ['valor' => 'false', 'descricao' => 'Promotor pode cancelar o próprio registro (App\Support\CancelamentoRegistro)'],
        'VISITA_CANCELAMENTO_PERMITIDO' => ['valor' => 'false', 'descricao' => 'Promotor pode cancelar a própria visita em andamento (App\Support\CancelamentoVisita)'],
        'SORTIMENTO_AUTONOMIA_PROMOTOR' => ['valor' => 'AUTONOMO', 'descricao' => 'Autonomia pra vincular produto já existente ao sortimento do PDV (App\Support\AutonomiaSortimento)'],
        'CATALOGO_AUTONOMIA_PROMOTOR' => ['valor' => 'REQUER_APROVACAO', 'descricao' => 'Autonomia pra cadastrar produto novo no catálogo (App\Support\AutonomiaSortimento)'],
        'CODIGO_BARRAS_OBRIGATORIO' => ['valor' => 'false', 'descricao' => 'Exige código de barras ao cadastrar produto (App\Support\CodigoBarrasProduto)'],
        'CODIGO_BARRAS_UNICO' => ['valor' => 'false', 'descricao' => 'Código de barras precisa ser único no catálogo da empresa (App\Support\CodigoBarrasProduto)'],
        'RASTREAMENTO_INTERVALO_SEGUNDOS' => ['valor' => '0', 'descricao' => 'Intervalo do rastreamento em tempo real, em segundos — 0 = desligado (App\Support\Rastreamento)'],
        'RASTREAMENTO_EXIGENCIA' => ['valor' => 'OPCIONAL', 'descricao' => 'Quanto o app exige do promotor pra manter o rastreamento ligado: OPCIONAL, AVISO (faixa fixa) ou OBRIGATORIO (bloqueia o app) — docs/47'],
        'RASTREAMENTO_SO_NA_JORNADA' => ['valor' => 'true', 'descricao' => 'A exigência de rastreamento só vale dentro de JORNADA_INICIO/JORNADA_FIM — false = o dia inteiro (docs/47)'],
        'RASTREAMENTO_PAINEL_CONFORMIDADE' => ['valor' => 'false', 'descricao' => 'Mostra no Mapa ao vivo a lista de promotores com rastreamento irregular e o motivo (docs/47)'],
        'RASTREAMENTO_HISTORICO_DIAS' => ['valor' => '90', 'descricao' => 'Por quantos dias as posições do promotor ficam guardadas pra Rota do dia (docs/48) — o mais antigo é apagado todo dia'],
        'RASTREAMENTO_PARADA_MINUTOS' => ['valor' => '30', 'descricao' => 'A partir de quantos minutos parado no mesmo lugar, sem loja por perto, conta como parada fora de loja na Rota do dia (docs/48)'],
        'PEDIDO_VENDA_SEM_VISITA_PERMITIDO' => ['valor' => 'false', 'descricao' => 'Vendedor pode tirar Pedido de Venda fora de uma visita (App\Support\PedidoVendaSemVisita) — false = só durante a visita'],
        'ATIVIDADES_POLLING_SEGUNDOS' => ['valor' => '30', 'descricao' => 'Intervalo de atualização automática do Painel de Atividades, em segundos (docs/19)'],
        'ATIVIDADES_ALERTA_REQUER_RESOLUCAO' => ['valor' => 'false', 'descricao' => 'Alertas do Painel de Atividades ficam pendentes até alguém resolver (docs/19) — false = só informativos'],
        'ATRASO_TOLERANCIA_MINUTOS' => ['valor' => '30', 'descricao' => 'Tolerância, em minutos, antes de considerar o promotor atrasado na Operação do Dia (App\Support\OperacaoDoDia)'],
        'JORNADA_INICIO' => ['valor' => '07:00', 'descricao' => 'Início da jornada de trabalho (HH:mm) — régua da Operação do Dia (App\Support\OperacaoDoDia)'],
        'JORNADA_FIM' => ['valor' => '17:00', 'descricao' => 'Fim da jornada de trabalho (HH:mm) — régua da Operação do Dia (App\Support\OperacaoDoDia)'],
        'DIRECIONAMENTO_BLOQUEIA_CHECKOUT' => ['valor' => 'false', 'descricao' => 'Checkout recusado enquanto houver formulário de Direcionamento pendente (App\Support\DirecionamentoParametros) — false = só avisa'],
        'PLANEJADOR_VISITAS_OMITIR_DOMINGO' => ['valor' => 'false', 'descricao' => 'Esconde o domingo no Planejador de Visitas e no Relatório de Rota (App\Support\DiasSemanaVisiveis)'],
        'PLANEJADOR_VISITAS_OMITIR_SABADO' => ['valor' => 'false', 'descricao' => 'Esconde o sábado no Planejador de Visitas e no Relatório de Rota (App\Support\DiasSemanaVisiveis)'],
    ];

    /**
     * Situação de cada parâmetro do catálogo na empresa (pra tela do superadmin mostrar o que falta).
     *
     * @return list<array{chave: string, valor_padrao: string, descricao: string, cadastrado: bool}>
     */
    public static function situacao(Empresa $empresa): array
    {
        $existentes = self::chavesExistentes($empresa);

        return collect(self::CATALOGO)
            ->map(fn (array $config, string $chave) => [
                'chave' => $chave,
                'valor_padrao' => $config['valor'],
                'descricao' => $config['descricao'],
                'cadastrado' => in_array($chave, $existentes, true),
            ])
            ->values()
            ->all();
    }

    /**
     * Cadastra na empresa só os parâmetros do catálogo que ela ainda não tem (ativos, com o valor
     * padrão). Idempotente: rodar de novo não faz nada.
     *
     * @return list<string> chaves criadas
     */
    public static function completar(Empresa $empresa): array
    {
        $existentes = self::chavesExistentes($empresa);
        $criadas = [];

        foreach (self::CATALOGO as $chave => $config) {
            if (in_array($chave, $existentes, true)) {
                continue;
            }

            // withoutGlobalScopes: quem chama pode ser o SUPERADMIN (sem empresa) ou um comando de
            // console — o empresa_id vai explícito, nunca herdado do usuário logado.
            Parametro::withoutGlobalScopes()->create([
                'empresa_id' => $empresa->id,
                'chave' => $chave,
                'valor' => $config['valor'],
                'descricao' => $config['descricao'],
                'ativo' => true,
            ]);
            $criadas[] = $chave;
        }

        return $criadas;
    }

    /** @return list<string> inclui parâmetros desativados — desativado continua "existindo". */
    private static function chavesExistentes(Empresa $empresa): array
    {
        return Parametro::withoutGlobalScopes()
            ->where('empresa_id', $empresa->id)
            ->pluck('chave')
            ->all();
    }
}

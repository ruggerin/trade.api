<?php

namespace App\Support;

use App\Models\Empresa;
use App\Models\RelatorioPersonalizado;
use App\Relatorios\ExecutorRelatorio;

/**
 * Relatórios pré-construídos em todo cliente (docs/60 §6). São do sistema: o seed cria o que falta
 * e atualiza o que tem `versao` maior no catálogo; o cliente que quer mudar duplica. Relatório do
 * cliente (`padrao = false`) nunca é tocado.
 *
 * Padrão novo = uma entrada aqui; mudar um existente = mudar a definição e subir a `versao`.
 */
final class RelatoriosPadrao
{
    public const CATALOGO = [
        // Tradução da tela fixa "Cumprimento de visitas" (docs/59 §4.1).
        'visitas_planejadas_canceladas_executadas' => [
            'versao' => 1,
            'nome' => 'Planejadas × canceladas × executadas',
            'descricao' => 'Visitas planejadas por dia e promotor: executadas, em aberto, canceladas e o cumprimento.',
            'definicao' => [
                'entidade' => 'ordem_servico',
                'periodo' => ['campo' => 'prazo_fim', 'preset' => 'ultimos_7_dias'],
                'filtros' => ['combinador' => 'E', 'regras' => []],
                'agrupar' => [['campo' => 'prazo_fim', 'granularidade' => 'dia'], ['campo' => 'promotor']],
                'metricas' => [
                    ['chave' => 'planejadas'],
                    ['chave' => 'executadas'],
                    ['chave' => 'em_andamento'],
                    ['chave' => 'atrasadas'],
                    ['chave' => 'a_vencer'],
                    ['chave' => 'canceladas'],
                    ['chave' => 'canceladas_promotor'],
                    ['chave' => 'cumprimento'],
                    ['chave' => 'cumprimento_ajustado'],
                    ['chave' => 'canceladas_por_responsavel'],
                    ['chave' => 'canceladas_por_motivo'],
                ],
                'comparar' => 'anterior',
            ],
        ],
        // Tradução da tela fixa "Tempo no PDV" (docs/59 §4.2).
        'tempo_no_pdv' => [
            'versao' => 1,
            'nome' => 'Tempo dentro do PDV',
            'descricao' => 'Tempo efetivo na loja (duração menos o afastamento), por loja.',
            'definicao' => [
                'entidade' => 'visita',
                'periodo' => ['campo' => 'inicio_data', 'preset' => 'ultimos_7_dias'],
                'filtros' => ['combinador' => 'E', 'regras' => []],
                'agrupar' => [['campo' => 'loja']],
                'metricas' => [
                    ['chave' => 'visitas'],
                    ['chave' => 'tempo_medio'],
                    ['chave' => 'tempo_mediano'],
                    ['chave' => 'tempo_total'],
                    ['chave' => 'desconsideradas'],
                ],
                'comparar' => 'anterior',
                'ordenar' => ['chave' => 'tempo_total', 'direcao' => 'desc'],
            ],
        ],
        // Registros (docs/60 Fase 7): onde estão as rupturas e os alertas sem tratativa.
        'rupturas_e_alertas_por_loja' => [
            'versao' => 1,
            'nome' => 'Rupturas e alertas por loja',
            'descricao' => 'Registros por loja: rupturas, % de ruptura e alertas em aberto.',
            'definicao' => [
                'entidade' => 'registro',
                'periodo' => ['campo' => 'registrado_em', 'preset' => 'ultimos_30_dias'],
                'filtros' => ['combinador' => 'E', 'regras' => []],
                'agrupar' => [['campo' => 'loja']],
                'metricas' => [
                    ['chave' => 'registros'],
                    ['chave' => 'rupturas'],
                    ['chave' => 'percentual_ruptura'],
                    ['chave' => 'alertas'],
                    ['chave' => 'alertas_abertos'],
                ],
                'comparar' => 'anterior',
                'ordenar' => ['chave' => 'rupturas', 'direcao' => 'desc'],
            ],
        ],
    ];

    /**
     * Cria os padrão que faltam e atualiza os de versão antiga. Idempotente.
     *
     * @return array{criados: list<string>, atualizados: list<string>}
     */
    public static function completar(Empresa $empresa, bool $simular = false): array
    {
        $existentes = RelatorioPersonalizado::withoutGlobalScopes()
            ->where('empresa_id', $empresa->id)
            ->where('padrao', true)
            ->whereNotNull('chave')
            ->get()
            ->keyBy('chave');

        $resultado = ['criados' => [], 'atualizados' => []];

        foreach (self::CATALOGO as $chave => $config) {
            $atual = $existentes->get($chave);
            if ($atual !== null && $atual->versao >= $config['versao']) {
                continue;
            }

            $resultado[$atual ? 'atualizados' : 'criados'][] = $chave;
            if ($simular) {
                continue;
            }

            $dados = [
                'nome' => $config['nome'],
                'descricao' => $config['descricao'],
                'entidade' => $config['definicao']['entidade'],
                // Normalizada pelo mesmo validador do editor: padrão inválido quebra aqui, não na tela.
                'definicao' => ExecutorRelatorio::validar($config['definicao']),
                'compartilhado' => true,
                'padrao' => true,
                'versao' => $config['versao'],
            ];

            if ($atual) {
                $atual->update($dados);
            } else {
                RelatorioPersonalizado::withoutGlobalScopes()->create($dados + [
                    'empresa_id' => $empresa->id,
                    'usuario_id' => null,
                    'chave' => $chave,
                ]);
            }
        }

        return $resultado;
    }
}

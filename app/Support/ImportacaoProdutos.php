<?php

namespace App\Support;

use App\Enums\Propriedade;
use App\Models\DepartamentoAuditoria;
use App\Models\Empresa;
use App\Models\MarcaAuditoria;
use App\Models\ProdutoAuditoria;
use App\Models\SecaoAuditoria;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Cadastro de produtos em lote via CSV (docs/42-IMPORTACAO-DE-DADOS.md §4.2) — mesmo molde de
 * ImportacaoPontosVenda: `codigo_externo` é a chave (existe → atualiza só o que veio preenchido;
 * não existe → cria), tudo ou nada, `simular` valida sem gravar.
 *
 * - Departamento, seção e marca vão pelo NOME já cadastrado; desconhecido é erro (não cria).
 *   Seção com o mesmo nome em departamentos diferentes precisa da coluna departamento pra
 *   desempatar. Seção informada sem departamento preenche o departamento dela.
 * - Propriedade: "Própria"/"Concorrente" (sem acento/maiúscula). Vazio numa criação = Própria.
 * - Código de barras único quando o parâmetro CODIGO_BARRAS_UNICO está ligado.
 */
class ImportacaoProdutos
{
    public const MAX_LINHAS = 5000;

    public const COLUNAS = [
        'codigo_externo', 'descricao', 'codigo_barras', 'departamento', 'secao', 'marca', 'peso_kg',
        'propriedade', 'preco_tabela', 'desconto_maximo_pct', 'ativo',
    ];

    /**
     * @return array{total: int, criadas: int, atualizadas: int, erros: list<array{linha: int, codigo_externo: string|null, mensagem: string}>, aplicado: bool}
     */
    public static function executar(Empresa $empresa, string $conteudo, bool $simular): array
    {
        $linhas = LeitorCsv::ler($conteudo, self::COLUNAS, ['codigo_externo'], self::MAX_LINHAS, 'produto');
        if (isset($linhas['erro'])) {
            return self::relatorio(0, 0, 0, [['linha' => 1, 'codigo_externo' => null, 'mensagem' => $linhas['erro']]], false);
        }

        $departamentos = DepartamentoAuditoria::where('empresa_id', $empresa->id)->get()->keyBy(fn ($d) => LeitorCsv::chaveNome($d->descricao));
        $marcas = MarcaAuditoria::where('empresa_id', $empresa->id)->get()->keyBy(fn ($m) => LeitorCsv::chaveNome($m->descricao));
        $secoes = SecaoAuditoria::where('empresa_id', $empresa->id)->get()->groupBy(fn ($s) => LeitorCsv::chaveNome($s->descricao));

        $codigos = array_values(array_filter(array_map(fn ($l) => $l['dados']['codigo_externo'] ?? null, $linhas)));
        $existentes = ProdutoAuditoria::withoutGlobalScopes()
            ->where('empresa_id', $empresa->id)
            ->whereIn('codigo_externo', $codigos)
            ->get()
            ->groupBy('codigo_externo');

        $barrasUnico = CodigoBarrasProduto::unico($empresa);
        $donosBarras = $barrasUnico
            ? ProdutoAuditoria::withoutGlobalScopes()->where('empresa_id', $empresa->id)->whereNotNull('codigo_barras')->pluck('codigo_externo', 'codigo_barras')
            : collect();

        $erros = [];
        $operacoes = [];
        $vistos = [];
        $barrasNoArquivo = [];

        foreach ($linhas as ['numero' => $numero, 'dados' => $bruto]) {
            $codigo = $bruto['codigo_externo'] ?? null;
            $erro = function (string $mensagem) use (&$erros, $numero, $codigo): void {
                $erros[] = ['linha' => $numero, 'codigo_externo' => $codigo, 'mensagem' => $mensagem];
            };

            if (! $codigo) {
                $erro('Código externo vazio — ele é a chave do produto.');

                continue;
            }
            if (isset($vistos[$codigo])) {
                $erro("Código externo repetido no arquivo (já apareceu na linha {$vistos[$codigo]}).");

                continue;
            }
            $vistos[$codigo] = $numero;

            $atuais = $existentes->get($codigo);
            if ($atuais && $atuais->count() > 1) {
                $erro('Existe mais de um produto com este código externo no sistema — corrija no cadastro antes de importar.');

                continue;
            }
            $atual = $atuais?->first();

            $dados = array_filter($bruto, fn ($v) => $v !== null && $v !== '');
            $mensagens = [];

            foreach (['peso_kg', 'preco_tabela', 'desconto_maximo_pct'] as $campo) {
                if (isset($dados[$campo])) {
                    // Aceita "10,50" e "1.234,56" (pt-BR) e "10.5".
                    $v = $dados[$campo];
                    $dados[$campo] = str_contains($v, ',') ? str_replace(['.', ','], ['', '.'], $v) : $v;
                }
            }

            if (isset($dados['departamento'])) {
                $departamento = $departamentos->get(LeitorCsv::chaveNome($dados['departamento']));
                if ($departamento) {
                    $dados['departamento_id'] = $departamento->id;
                } else {
                    $mensagens[] = "Departamento \"{$dados['departamento']}\" não cadastrado.";
                }
            }
            if (isset($dados['secao'])) {
                $candidatas = $secoes->get(LeitorCsv::chaveNome($dados['secao']), collect());
                if (isset($dados['departamento_id'])) {
                    $candidatas = $candidatas->where('departamento_id', $dados['departamento_id']);
                }
                if ($candidatas->count() === 1) {
                    $secao = $candidatas->first();
                    $dados['secao_id'] = $secao->id;
                    $dados['departamento_id'] ??= $secao->departamento_id;
                } elseif ($candidatas->isEmpty()) {
                    $mensagens[] = "Seção \"{$dados['secao']}\" não cadastrada".(isset($dados['departamento']) ? " no departamento \"{$dados['departamento']}\"" : '').'.';
                } else {
                    $mensagens[] = "Existe mais de uma seção \"{$dados['secao']}\" — preencha o departamento pra desempatar.";
                }
            }
            if (isset($dados['marca'])) {
                $marca = $marcas->get(LeitorCsv::chaveNome($dados['marca']));
                if ($marca) {
                    $dados['marca_id'] = $marca->id;
                } else {
                    $mensagens[] = "Marca \"{$dados['marca']}\" não cadastrada.";
                }
            }
            unset($dados['departamento'], $dados['secao'], $dados['marca']);

            if (isset($dados['propriedade'])) {
                $propriedade = match (LeitorCsv::chaveNome(strtr($dados['propriedade'], ['ó' => 'o', 'Ó' => 'o']))) {
                    'propria', 'p' => Propriedade::PROPRIA,
                    'concorrente', 'c' => Propriedade::CONCORRENTE,
                    default => null,
                };
                if ($propriedade) {
                    $dados['propriedade'] = $propriedade;
                } else {
                    $mensagens[] = "Propriedade \"{$dados['propriedade']}\" inválida — use Própria ou Concorrente.";
                    unset($dados['propriedade']);
                }
            }
            if (isset($dados['ativo'])) {
                $ativo = LeitorCsv::booleano($dados['ativo']);
                if ($ativo === null) {
                    $mensagens[] = "Ativo \"{$dados['ativo']}\" inválido — use sim ou não.";
                    unset($dados['ativo']);
                } else {
                    $dados['ativo'] = $ativo;
                }
            }

            if (! $atual && ! isset($dados['descricao'])) {
                $mensagens[] = 'Produto novo: descricao é obrigatório.';
            }

            $validador = Validator::make($dados, [
                'codigo_externo' => ['string', 'max:64'],
                'descricao' => ['string', 'max:255'],
                'codigo_barras' => ['string', 'max:64'],
                'peso_kg' => ['numeric', 'min:0'],
                'preco_tabela' => ['numeric', 'gt:0', 'max:9999999'],
                'desconto_maximo_pct' => ['numeric', 'between:0,100'],
            ]);
            array_push($mensagens, ...$validador->errors()->all());

            if ($barrasUnico && isset($dados['codigo_barras'])) {
                $dono = $donosBarras->get($dados['codigo_barras']);
                if ($donosBarras->has($dados['codigo_barras']) && $dono !== $codigo) {
                    $mensagens[] = "Código de barras {$dados['codigo_barras']} já pertence a outro produto".($dono ? " (código {$dono})" : '').'.';
                } elseif (isset($barrasNoArquivo[$dados['codigo_barras']])) {
                    $mensagens[] = "Código de barras repetido no arquivo (linha {$barrasNoArquivo[$dados['codigo_barras']]}).";
                }
                $barrasNoArquivo[$dados['codigo_barras']] = $numero;
            }

            if ($mensagens) {
                $erro(implode(' ', $mensagens));

                continue;
            }

            $operacoes[] = ['atual' => $atual, 'dados' => $dados];
        }

        $novos = count(array_filter($operacoes, fn ($o) => ! $o['atual']));
        $atualizados = count($operacoes) - $novos;

        if ($erros || $simular) {
            return self::relatorio($novos, $atualizados, count($linhas), $erros, false);
        }

        DB::transaction(function () use ($empresa, $operacoes) {
            foreach ($operacoes as ['atual' => $atual, 'dados' => $dados]) {
                $atual
                    ? $atual->update($dados)
                    : ProdutoAuditoria::create(['propriedade' => Propriedade::PROPRIA, ...$dados, 'empresa_id' => $empresa->id]);
            }
        });

        return self::relatorio($novos, $atualizados, count($linhas), [], true);
    }

    private static function relatorio(int $criadas, int $atualizadas, int $total, array $erros, bool $aplicado): array
    {
        return ['total' => $total, 'criadas' => $criadas, 'atualizadas' => $atualizadas, 'erros' => $erros, 'aplicado' => $aplicado];
    }
}

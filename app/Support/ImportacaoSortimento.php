<?php

namespace App\Support;

use App\Enums\TipoItemCampanha;
use App\Models\Empresa;
use App\Models\PontoVenda;
use App\Models\ProdutoAuditoria;
use App\Models\SortimentoPontoVenda;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Vínculo Loja × Produto (sortimento) em lote via CSV (docs/42-IMPORTACAO-DE-DADOS.md §4.3) —
 * cada linha é um par (código externo da loja, código externo do produto).
 *
 * - ADITIVO por padrão: só cria o vínculo que não existe; nunca remove um que não apareceu no
 *   arquivo (reimportar um arquivo incompleto não pode apagar mix).
 * - `substituir` (docs/42 §4.3.1): cada loja DO ARQUIVO fica só com os produtos listados — os
 *   vínculos de PRODUTO dela que não estão no arquivo são apagados (hard delete, igual à remoção
 *   pela tela; pendentes de promotor inclusive). Itens de departamento/seção/marca e lojas fora do
 *   arquivo não mudam. Mesma transação dos inserts: qualquer erro, nada é apagado.
 * - Par que já existe conta como "já existia" (não é erro nem criação). Se ele estava PENDENTE/
 *   REJEITADO (criado por promotor, doc 14 §9), a importação do admin o aprova — é a fonte oficial.
 * - Vínculo criado aqui nasce aprovado (status_aprovacao null), igual ao cadastro pelo admin.
 * - Tudo ou nada; `simular` valida sem gravar. Até 50.000 linhas (todo PDV × mix), gravação em
 *   blocos pra não estourar memória nem o limite de parâmetros do Postgres.
 */
class ImportacaoSortimento
{
    public const MAX_LINHAS = 50000;

    public const COLUNAS = ['codigo_externo_loja', 'codigo_externo_produto'];

    private const BLOCO = 1000;

    /**
     * @return array{total: int, criadas: int, ja_existentes: int, removidas?: int, lojas?: int, erros: list<array{linha: int, codigo_externo: string|null, mensagem: string}>, aplicado: bool}
     */
    public static function executar(Empresa $empresa, string $conteudo, bool $simular, bool $substituir = false): array
    {
        $linhas = LeitorCsv::ler($conteudo, self::COLUNAS, self::COLUNAS, self::MAX_LINHAS, 'vínculo');
        if (isset($linhas['erro'])) {
            return self::relatorio(0, 0, 0, [['linha' => 1, 'codigo_externo' => null, 'mensagem' => $linhas['erro']]], false, $substituir ? [] : null, 0);
        }

        return self::aplicar($empresa, $linhas, $simular, $substituir);
    }

    /**
     * As mesmas regras pra linhas já lidas (API de integração, docs/96 §7.2).
     *
     * @param  list<array{numero: int, dados: array<string, string|null>}>  $linhas
     */
    public static function aplicar(Empresa $empresa, array $linhas, bool $simular, bool $substituir = false): array
    {
        $lojas = self::resolverPorCodigo(
            PontoVenda::withoutGlobalScopes()->where('empresa_id', $empresa->id),
            array_column(array_column($linhas, 'dados'), 'codigo_externo_loja'),
        );
        $produtos = self::resolverPorCodigo(
            ProdutoAuditoria::withoutGlobalScopes()->where('empresa_id', $empresa->id),
            array_column(array_column($linhas, 'dados'), 'codigo_externo_produto'),
        );

        // Pares já existentes (produto individual), com o status de aprovação atual. `$idsPorPar`
        // guarda todos os ids do par (pode haver linha duplicada) pra o substituir apagar tudo.
        $existentes = [];
        $idsPorPar = [];
        foreach (array_chunk(array_values(array_unique(array_filter($lojas))), 5000) as $idsLojas) {
            SortimentoPontoVenda::query()
                ->whereIn('ponto_venda_id', $idsLojas)
                ->where('tipo_item', TipoItemCampanha::PRODUTO)
                ->whereNotNull('produto_id')
                ->get(['id', 'ponto_venda_id', 'produto_id', 'status_aprovacao'])
                ->each(function ($s) use (&$existentes, &$idsPorPar) {
                    $existentes["{$s->ponto_venda_id}|{$s->produto_id}"] = ['id' => $s->id, 'pendente' => $s->status_aprovacao !== null];
                    $idsPorPar["{$s->ponto_venda_id}|{$s->produto_id}"][] = $s->id;
                });
        }

        $erros = [];
        $novos = [];
        $aAprovar = [];
        $jaExistentes = 0;
        $paresDoArquivo = [];
        $lojasDoArquivo = [];

        foreach ($linhas as ['numero' => $numero, 'dados' => $dados]) {
            $codLoja = $dados['codigo_externo_loja'] ?? '';
            $codProduto = $dados['codigo_externo_produto'] ?? '';
            $mensagens = [];

            if ($codLoja === '' || $codProduto === '') {
                $mensagens[] = 'Preencha o código externo da loja e do produto.';
            } else {
                $loja = $lojas[$codLoja] ?? null;
                $produto = $produtos[$codProduto] ?? null;
                if ($loja === null) {
                    $mensagens[] = "Loja com código \"{$codLoja}\" não encontrada.";
                } elseif ($loja === false) {
                    $mensagens[] = "Mais de uma loja com o código \"{$codLoja}\" — corrija no cadastro.";
                }
                if ($produto === null) {
                    $mensagens[] = "Produto com código \"{$codProduto}\" não encontrado.";
                } elseif ($produto === false) {
                    $mensagens[] = "Mais de um produto com o código \"{$codProduto}\" — corrija no cadastro.";
                }
            }

            if ($mensagens) {
                $erros[] = ['linha' => $numero, 'codigo_externo' => "{$codLoja} × {$codProduto}", 'mensagem' => implode(' ', $mensagens)];

                continue;
            }

            $chave = "{$lojas[$codLoja]}|{$produtos[$codProduto]}";
            $paresDoArquivo[$chave] = true;
            $lojasDoArquivo[$lojas[$codLoja]] = true;
            if (isset($existentes[$chave]) || isset($novos[$chave])) {
                // Já existe (ou repetido no próprio arquivo) — não é erro, só não cria de novo.
                $jaExistentes++;
                if (($existentes[$chave]['pendente'] ?? false) === true) {
                    $aAprovar[$existentes[$chave]['id']] = true;
                }

                continue;
            }
            $novos[$chave] = ['ponto_venda_id' => $lojas[$codLoja], 'produto_id' => $produtos[$codProduto]];
        }

        // Substituir: vínculos de PRODUTO das lojas do arquivo que não vieram nele. `$existentes`
        // já está limitado às lojas do arquivo e a tipo_item = PRODUTO.
        $aRemover = [];
        if ($substituir) {
            foreach ($idsPorPar as $chave => $ids) {
                if (! isset($paresDoArquivo[$chave])) {
                    array_push($aRemover, ...$ids);
                }
            }
        }
        $lojasAfetadas = $substituir ? count($lojasDoArquivo) : null;

        if ($erros || $simular) {
            return self::relatorio(count($novos), $jaExistentes, count($linhas), $erros, false, $substituir ? $aRemover : null, $lojasAfetadas);
        }

        DB::transaction(function () use ($novos, $aAprovar, $aRemover) {
            // Não ficar pendurado atrás de alguém editando o mesmo mix: falha (e desfaz tudo).
            DB::statement("SET LOCAL lock_timeout = '10s'");
            foreach (array_chunk($aRemover, self::BLOCO) as $ids) {
                SortimentoPontoVenda::whereIn('id', $ids)->delete();
            }
            $agora = now();
            foreach (array_chunk(array_values($novos), self::BLOCO) as $bloco) {
                // Insert direto (uuid vem do DEFAULT do banco) — criar 50.000 models um a um
                // levaria minutos.
                DB::table('sortimentos_ponto_venda')->insert(array_map(fn ($n) => [
                    ...$n,
                    'tipo_item' => TipoItemCampanha::PRODUTO->value,
                    'status_aprovacao' => null,
                    'created_at' => $agora,
                    'updated_at' => $agora,
                ], $bloco));
            }
            foreach (array_chunk(array_keys($aAprovar), self::BLOCO) as $ids) {
                SortimentoPontoVenda::whereIn('id', $ids)->update(['status_aprovacao' => null]);
            }
        });

        return self::relatorio(count($novos), $jaExistentes, count($linhas), [], true, $substituir ? $aRemover : null, $lojasAfetadas);
    }

    /**
     * codigo_externo → id; `false` quando o código é ambíguo (mais de um registro com ele).
     *
     * @param  list<string|null>  $codigos
     * @return array<string, int|false>
     */
    private static function resolverPorCodigo($query, array $codigos): array
    {
        $codigos = array_values(array_unique(array_filter($codigos, fn ($c) => $c !== null && $c !== '')));
        $mapa = [];
        foreach (array_chunk($codigos, 5000) as $bloco) {
            /** @var Collection $encontrados */
            $encontrados = (clone $query)->whereIn('codigo_externo', $bloco)->get(['id', 'codigo_externo']);
            foreach ($encontrados->groupBy('codigo_externo') as $codigo => $grupo) {
                $mapa[(string) $codigo] = $grupo->count() > 1 ? false : $grupo->first()->id;
            }
        }

        return $mapa;
    }

    /** `$removidas` null = modo aditivo (o relatório nem traz a chave, o diálogo diferencia por ela). */
    private static function relatorio(int $criadas, int $jaExistentes, int $total, array $erros, bool $aplicado, ?array $removidas = null, ?int $lojas = null): array
    {
        $relatorio = ['total' => $total, 'criadas' => $criadas, 'ja_existentes' => $jaExistentes, 'erros' => $erros, 'aplicado' => $aplicado];
        if ($removidas !== null) {
            $relatorio['removidas'] = count($removidas);
            $relatorio['lojas'] = $lojas ?? 0;
        }

        return $relatorio;
    }
}

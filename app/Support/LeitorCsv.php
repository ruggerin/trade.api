<?php

namespace App\Support;

/**
 * Leitura genérica de CSV das importações em lote (docs/42-IMPORTACAO-DE-DADOS.md §3.1) — a parte
 * que não depende da entidade: BOM, encoding (UTF-8 ou o Windows-1252 que o Excel pt-BR salva),
 * separador `;` ou `,` (pelo cabeçalho), cabeçalho sem diferença de acento/maiúscula ("Código
 * Externo" = `codigo_externo`), linhas em branco, limite de linhas. Cada importação
 * (ImportacaoPontosVenda, ImportacaoProdutos, ImportacaoSortimento) cuida só da própria regra.
 */
class LeitorCsv
{
    /**
     * @param  list<string>  $colunasPermitidas  cabeçalho canônico aceito
     * @param  list<string>  $colunasObrigatorias  precisam estar no cabeçalho
     * @return list<array{numero: int, dados: array<string, string|null>}>|array{erro: string}
     */
    public static function ler(string $conteudo, array $colunasPermitidas, array $colunasObrigatorias, int $maxLinhas, string $nomeItem): array
    {
        $conteudo = preg_replace('/^\xEF\xBB\xBF/', '', $conteudo);
        if (! mb_check_encoding($conteudo, 'UTF-8')) {
            // Excel pt-BR salva "CSV" em Windows-1252 — sem isso, acento vira lixo.
            $conteudo = mb_convert_encoding($conteudo, 'UTF-8', 'Windows-1252');
        }

        $linhasTexto = preg_split('/\r\n|\n|\r/', trim($conteudo));
        if (! $linhasTexto || trim($linhasTexto[0]) === '') {
            return ['erro' => 'Arquivo vazio.'];
        }

        $separador = substr_count($linhasTexto[0], ';') >= substr_count($linhasTexto[0], ',') ? ';' : ',';
        $cabecalho = array_map(fn ($c) => self::normalizarCabecalho($c), str_getcsv($linhasTexto[0], $separador, '"', ''));

        $faltando = array_diff($colunasObrigatorias, $cabecalho);
        if ($faltando) {
            return ['erro' => 'Cabeçalho sem a(s) coluna(s) '.implode(', ', array_map(fn ($c) => "\"{$c}\"", $faltando)).' — use o arquivo modelo.'];
        }
        $desconhecidas = array_diff(array_filter($cabecalho), $colunasPermitidas);
        if ($desconhecidas) {
            return ['erro' => 'Coluna(s) desconhecida(s): '.implode(', ', $desconhecidas).' — use o arquivo modelo.'];
        }

        $linhas = [];
        foreach (array_slice($linhasTexto, 1) as $i => $texto) {
            if (trim($texto, " \t;,") === '') {
                continue; // linha em branco (comum no fim do arquivo do Excel)
            }
            $valores = str_getcsv($texto, $separador, '"', '');
            $dados = [];
            foreach ($cabecalho as $j => $coluna) {
                if ($coluna !== '') {
                    $dados[$coluna] = isset($valores[$j]) ? trim($valores[$j]) : null;
                }
            }
            $linhas[] = ['numero' => $i + 2, 'dados' => $dados];
        }

        if (! $linhas) {
            return ['erro' => "Nenhum(a) {$nomeItem} no arquivo — só o cabeçalho."];
        }
        if (count($linhas) > $maxLinhas) {
            return ['erro' => 'Máximo de '.number_format($maxLinhas, 0, ',', '.')." linhas por arquivo — divida em partes."];
        }

        return $linhas;
    }

    /** "Código Externo", "codigo externo", "CODIGO_EXTERNO" → codigo_externo. */
    public static function normalizarCabecalho(string $coluna): string
    {
        $coluna = mb_strtolower(trim($coluna));
        $coluna = strtr($coluna, ['á' => 'a', 'à' => 'a', 'ã' => 'a', 'â' => 'a', 'é' => 'e', 'ê' => 'e', 'í' => 'i', 'ó' => 'o', 'õ' => 'o', 'ô' => 'o', 'ú' => 'u', 'ç' => 'c']);

        return trim(preg_replace('/[^a-z0-9]+/', '_', $coluna), '_');
    }

    /** Chave pra casar nomes (rede, marca...) sem diferença de maiúscula/espaço nas pontas. */
    public static function chaveNome(string $nome): string
    {
        return mb_strtolower(trim($nome));
    }

    /** sim/não (e variações) → bool; null quando não reconhece. */
    public static function booleano(string $valor): ?bool
    {
        $valor = mb_strtolower(trim($valor));
        if (in_array($valor, ['1', 'sim', 's', 'true', 'ativo', 'yes'], true)) {
            return true;
        }
        if (in_array($valor, ['0', 'nao', 'não', 'n', 'false', 'inativo', 'no'], true)) {
            return false;
        }

        return null;
    }
}

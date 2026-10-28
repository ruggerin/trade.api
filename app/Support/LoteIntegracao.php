<?php

namespace App\Support;

/**
 * Lote de escrita da API de integração (docs/96 §7.2): upsert por código do ERP com resultado item
 * a item, e um item ruim não derruba o lote.
 *
 * Reaproveita as regras das importações por planilha (que são "tudo ou nada"): o lote passa uma vez
 * em simulação pra achar os itens com erro e depois é aplicado só com os válidos. Assim API e
 * planilha validam igual, e as regras entre itens (código repetido no lote, limite do plano) valem
 * pro lote todo. Erro que não é de um item (linha 0, ex.: limite do plano) recusa o lote inteiro.
 *
 * `$aplicar(array $linhas, bool $simular): array` recebe linhas no formato do LeitorCsv
 * (`[['numero' => n, 'dados' => [...]]]`) e devolve o relatório das importações (`erros` com
 * `linha`). O número da linha é a posição do item no lote, começando em 1.
 */
class LoteIntegracao
{
    public const MAX_ITENS = 500;

    /**
     * @param  list<mixed>  $itens  o array do corpo da requisição
     * @param  list<string>|null  $colunas  campos aceitos; null = item vai cru (pedidos, com itens aninhados)
     * @param  callable(array<string, mixed>): ?string  $chave  identifica o item no resultado (código do ERP)
     * @param  callable(list<string>): array<string, true>  $existentes  chaves que já existem antes de gravar
     * @param  callable(list<array{numero: int, dados: array}>, bool): array  $aplicar
     * @return array{simulacao: bool, total: int, criados: int, atualizados: int, erros: int, resultados: list<array>, erro_geral?: string, detalhes?: array}
     */
    public static function executar(array $itens, ?array $colunas, callable $chave, callable $existentes, callable $aplicar, bool $simular): array
    {
        $resultados = [];
        $linhas = [];

        foreach (array_values($itens) as $i => $item) {
            $numero = $i + 1;
            if (! is_array($item) || array_is_list($item) && $item !== []) {
                $resultados[$numero] = self::erro($numero, null, 'Cada item precisa ser um objeto.');

                continue;
            }
            if ($colunas !== null) {
                $desconhecidos = array_diff(array_keys($item), $colunas);
                if ($desconhecidos) {
                    $resultados[$numero] = self::erro($numero, $chave($item), 'Campo(s) desconhecido(s): '.implode(', ', $desconhecidos).'.');

                    continue;
                }
                $aninhado = array_filter($item, fn ($v) => is_array($v));
                if ($aninhado) {
                    $resultados[$numero] = self::erro($numero, $chave($item), 'Campo(s) '.implode(', ', array_keys($aninhado)).' não aceitam lista ou objeto.');

                    continue;
                }
                $item = array_map(self::texto(...), $item);
            }
            $linhas[] = ['numero' => $numero, 'dados' => $item];
        }

        $jaExistiam = $existentes(array_values(array_filter(array_map(fn ($l) => $chave($l['dados']), $linhas))));

        $validacao = $linhas ? $aplicar($linhas, true) : ['erros' => []];
        foreach ($validacao['erros'] ?? [] as $e) {
            if ((int) $e['linha'] === 0) {
                return self::relatorio($simular, count($itens), [], $e['mensagem']);
            }
            $resultados[$e['linha']] = self::erro($e['linha'], $e['codigo_externo'] ?? null, $e['mensagem']);
        }

        $validas = array_values(array_filter($linhas, fn ($l) => ! isset($resultados[$l['numero']])));
        $detalhes = null;
        if ($validas && ! $simular) {
            $aplicado = $aplicar($validas, false);
            // As linhas já passaram na simulação; erro aqui seria corrida com outra gravação.
            foreach ($aplicado['erros'] ?? [] as $e) {
                if ((int) $e['linha'] === 0) {
                    return self::relatorio($simular, count($itens), [], $e['mensagem']);
                }
                $resultados[$e['linha']] = self::erro($e['linha'], $e['codigo_externo'] ?? null, $e['mensagem']);
            }
            $detalhes = array_diff_key($aplicado, array_flip(['total', 'criadas', 'atualizadas', 'erros', 'aplicado', 'ja_existentes']));
        }

        foreach ($validas as $l) {
            if (isset($resultados[$l['numero']])) {
                continue;
            }
            $codigo = $chave($l['dados']);
            $resultados[$l['numero']] = [
                'item' => $l['numero'],
                'codigo_externo' => $codigo,
                'result' => $codigo !== null && isset($jaExistiam[$codigo]) ? 'updated' : 'created',
            ];
        }

        ksort($resultados);

        return self::relatorio($simular, count($itens), array_values($resultados), null, $detalhes ?: null);
    }

    /** Valor do JSON no formato que a planilha entrega: texto aparado, sim/não, ponto decimal. */
    public static function texto(mixed $valor): ?string
    {
        return match (true) {
            $valor === null => null,
            is_bool($valor) => $valor ? 'sim' : 'nao',
            is_int($valor), is_float($valor) => (string) $valor,
            default => trim((string) $valor),
        };
    }

    private static function erro(int $numero, ?string $codigo, string $mensagem): array
    {
        return ['item' => $numero, 'codigo_externo' => $codigo, 'result' => 'error', 'message' => $mensagem];
    }

    private static function relatorio(bool $simular, int $total, array $resultados, ?string $erroGeral, ?array $detalhes = null): array
    {
        $contar = fn (string $r) => count(array_filter($resultados, fn ($x) => $x['result'] === $r));

        return array_filter([
            'dry_run' => $simular,
            'total' => $total,
            'created' => $contar('created'),
            'updated' => $contar('updated'),
            'errors' => $contar('error'),
            'results' => $resultados,
            'error' => $erroGeral,
            'details' => $detalhes,
        ], fn ($v) => $v !== null);
    }
}

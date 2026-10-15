<?php

namespace App\Support;

use App\Models\Empresa;
use App\Models\PontoVenda;
use App\Models\Vendedor;
use Illuminate\Support\Facades\DB;

/**
 * Carteira vendedor × loja vinda do ERP (docs/73 §11.1), em lote: cada linha é um par (código da
 * loja, código do vendedor). Uma loja pode ter vários vendedores.
 *
 * - SUBSTITUI POR LOJA: cada loja que aparece no lote fica exatamente com os vendedores listados
 *   pra ela (quem saiu da carteira no ERP sai aqui). Loja que não aparece no lote não é tocada.
 *   É o contrário do sortimento (só adiciona): aqui o ERP é a fonte da carteira inteira da loja.
 * - Planilha é tudo ou nada (com `simular`); o integrador grava as lojas cujas linhas são todas
 *   válidas e devolve as recusadas — uma loja com linha ruim fica como estava, inteira.
 * - Ajuste fino 1 a 1 é pela tela da loja (PontoVendaController::vincularVendedor).
 */
final class CarteiraVendedores
{
    public const MAX_LINHAS = 50000;

    public const COLUNAS = ['codigo_externo_loja', 'codigo_externo_vendedor'];

    /**
     * @param  list<array{numero: int, dados: array<string, mixed>}>  $linhas
     * @return array{total: int, lojas: int, criadas: int, removidas: int, erros: list<array<string, mixed>>, aplicado: bool}
     */
    public static function executar(Empresa $empresa, array $linhas, bool $tudoOuNada, bool $simular = false): array
    {
        $texto = fn ($v) => is_scalar($v) && trim((string) $v) !== '' ? trim((string) $v) : null;

        $lojas = self::porCodigo(PontoVenda::withoutGlobalScopes()->where('empresa_id', $empresa->id), array_map(fn ($l) => $texto($l['dados']['codigo_externo_loja'] ?? null), $linhas));
        $vendedores = self::porCodigo(Vendedor::withoutGlobalScopes()->where('empresa_id', $empresa->id), array_map(fn ($l) => $texto($l['dados']['codigo_externo_vendedor'] ?? null), $linhas));

        $erros = [];
        $desejado = []; // ponto_venda_id => [vendedor_id => true]
        $lojasComErro = [];

        foreach ($linhas as ['numero' => $numero, 'dados' => $dados]) {
            $loja = $texto($dados['codigo_externo_loja'] ?? null);
            $vendedor = $texto($dados['codigo_externo_vendedor'] ?? null);
            $falha = function (string $mensagem) use (&$erros, $numero, $loja): void {
                $erros[] = ['linha' => $numero, 'codigo_externo' => $loja, 'mensagem' => $mensagem];
            };

            $mensagens = [];
            if ($loja === null) {
                $mensagens[] = 'Código da loja vazio.';
            } elseif (! array_key_exists($loja, $lojas)) {
                $mensagens[] = "Loja {$loja} não cadastrada.";
            } elseif ($lojas[$loja] === false) {
                $mensagens[] = "Mais de uma loja com o código {$loja}: corrija o cadastro antes.";
            }
            if ($vendedor === null) {
                $mensagens[] = 'Código do vendedor vazio.';
            } elseif (! array_key_exists($vendedor, $vendedores)) {
                $mensagens[] = "Vendedor {$vendedor} não cadastrado: importe os vendedores antes.";
            }

            if ($mensagens) {
                $falha(implode(' ', $mensagens));
                if ($loja !== null && ($lojas[$loja] ?? false)) {
                    $lojasComErro[$lojas[$loja]] = true;
                }

                continue;
            }

            // Par repetido no lote não é erro: vira um vínculo só.
            $desejado[$lojas[$loja]][$vendedores[$vendedor]] = true;
        }

        // Loja com qualquer linha recusada não é substituída pela metade.
        $desejado = array_diff_key($desejado, $lojasComErro);

        $atuais = [];
        foreach (array_chunk(array_keys($desejado), 5000) as $bloco) {
            DB::table('vendedor_ponto_venda')->whereIn('ponto_venda_id', $bloco)->get(['ponto_venda_id', 'vendedor_id'])
                ->each(function ($v) use (&$atuais) {
                    $atuais[$v->ponto_venda_id][$v->vendedor_id] = true;
                });
        }

        $inserir = [];
        $remover = [];
        foreach ($desejado as $lojaId => $vendedoresDaLoja) {
            foreach (array_keys(array_diff_key($vendedoresDaLoja, $atuais[$lojaId] ?? [])) as $vendedorId) {
                $inserir[] = ['ponto_venda_id' => $lojaId, 'vendedor_id' => $vendedorId];
            }
            foreach (array_keys(array_diff_key($atuais[$lojaId] ?? [], $vendedoresDaLoja)) as $vendedorId) {
                $remover[] = [$lojaId, $vendedorId];
            }
        }

        $relatorio = fn (bool $aplicado) => [
            'total' => count($linhas), 'lojas' => count($desejado), 'criadas' => count($inserir),
            'removidas' => count($remover), 'erros' => $erros, 'aplicado' => $aplicado,
        ];

        if ($simular || ($tudoOuNada && $erros) || ! $desejado) {
            return $relatorio(false);
        }

        DB::transaction(function () use ($inserir, $remover) {
            $agora = now();
            foreach (array_chunk($inserir, 1000) as $bloco) {
                DB::table('vendedor_ponto_venda')->insert(array_map(fn ($p) => $p + ['created_at' => $agora, 'updated_at' => $agora], $bloco));
            }
            foreach ($remover as [$lojaId, $vendedorId]) {
                DB::table('vendedor_ponto_venda')->where('ponto_venda_id', $lojaId)->where('vendedor_id', $vendedorId)->delete();
            }
        });

        return $relatorio(true);
    }

    /**
     * Código externo → id. `false` quando o código aparece em mais de um cadastro (loja pode ter
     * código repetido; o vendedor não, pela unicidade, mas o tratamento é o mesmo).
     *
     * @return array<string, int|false>
     */
    private static function porCodigo($query, array $codigos): array
    {
        $codigos = array_values(array_unique(array_filter($codigos)));
        $mapa = [];
        foreach (array_chunk($codigos, 5000) as $bloco) {
            foreach ((clone $query)->whereIn('codigo_externo', $bloco)->get(['id', 'codigo_externo'])->groupBy('codigo_externo') as $codigo => $grupo) {
                $mapa[(string) $codigo] = $grupo->count() > 1 ? false : $grupo->first()->id;
            }
        }

        return $mapa;
    }
}

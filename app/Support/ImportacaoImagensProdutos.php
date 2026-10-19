<?php

namespace App\Support;

use App\Enums\StatusAprovacao;
use App\Models\Empresa;
use App\Models\ProdutoAuditoria;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Importação de imagens de produtos pelo nome do arquivo (docs/89-IMPORTACAO-DE-IMAGENS-DE-PRODUTOS.md).
 * O nome (sem extensão) é o código de barras OU o código externo do produto — uma chave por lote,
 * escolhida na tela (decisão 1). Duas etapas: `resolver` só com os nomes (prévia, sem arquivo) e
 * `importar` com os arquivos, em lotes pequenos, revalidando cada um (a prévia pode estar velha).
 * Diferente das importações de CSV, não é tudo ou nada: cada arquivo é independente (decisão 3).
 */
class ImportacaoImagensProdutos
{
    public const CHAVES = ['codigo_barras', 'codigo_externo'];

    public const MAXIMO_NOMES = 5000;

    public const MAXIMO_ARQUIVOS_POR_LOTE = 10;

    public const SITUACOES = ['ok', 'nao_encontrado', 'ambiguo', 'duplicado', 'ja_tem_imagem', 'formato_invalido'];

    /**
     * @param  list<string>  $nomes
     * @return array{resultados: list<array<string, mixed>>, resumo: array<string, int>}
     */
    public static function resolver(Empresa $empresa, string $chave, array $nomes, bool $substituir): array
    {
        $codigos = array_map(fn (string $nome) => self::codigoDoNome($nome, $chave), $nomes);
        $candidatos = self::candidatos($empresa, $chave, array_values(array_filter($codigos)));

        $resultados = [];
        foreach ($nomes as $i => $nome) {
            $resultados[] = ['nome' => $nome, ...self::situacao($codigos[$i], $nome, $chave, $candidatos, $substituir)];
        }

        // Dois arquivos pro mesmo produto (1001.jpg e 1001.png): os dois ficam de fora — não dá
        // pra saber qual o usuário quer.
        $porProduto = collect($resultados)->filter(fn ($r) => $r['situacao'] === 'ok' || $r['situacao'] === 'ja_tem_imagem')
            ->countBy(fn ($r) => $r['produto']['id']);
        foreach ($resultados as &$r) {
            if (isset($r['produto']) && ($porProduto[$r['produto']['id']] ?? 0) > 1) {
                $r['situacao'] = 'duplicado';
                $r['mensagem'] = 'Mais de um arquivo para o mesmo produto: deixe só um.';
            }
        }
        unset($r);

        return ['resultados' => $resultados, 'resumo' => self::resumo($resultados)];
    }

    /**
     * @param  list<UploadedFile>  $arquivos
     * @return array{resultados: list<array<string, mixed>>, resumo: array<string, int>}
     */
    public static function importar(Empresa $empresa, string $chave, array $arquivos, bool $substituir): array
    {
        $nomes = array_map(fn (UploadedFile $a) => $a->getClientOriginalName(), $arquivos);
        $resolucao = self::resolver($empresa, $chave, $nomes, $substituir);

        $resultados = [];
        foreach ($resolucao['resultados'] as $i => $r) {
            if ($r['situacao'] === 'ok') {
                $produto = ProdutoAuditoria::withoutGlobalScopes()
                    ->where('empresa_id', $empresa->id)
                    ->where('uuid', $r['produto']['id'])
                    ->firstOrFail();
                ImagemProduto::gravar($produto, $arquivos[$i]);
                $r['produto']['imagem_url'] = $produto->imagem_url;
                $r['situacao'] = 'gravado';
            }
            $resultados[] = $r;
        }

        return ['resultados' => $resultados, 'resumo' => self::resumo($resultados)];
    }

    /** `7891000100103.JPG` → `7891000100103`; null se a extensão não é aceita ou o código fica vazio. */
    public static function codigoDoNome(string $nome, string $chave): ?string
    {
        $base = basename(str_replace('\\', '/', trim($nome)));
        $ponto = strrpos($base, '.');
        if ($ponto === false || ! in_array(strtolower(substr($base, $ponto + 1)), ImagemProduto::EXTENSOES, true)) {
            return null;
        }
        $codigo = trim(substr($base, 0, $ponto));
        if ($chave === 'codigo_barras') {
            $codigo = preg_replace('/\D/', '', $codigo);
        }

        return $codigo === '' ? null : $codigo;
    }

    private static function extensaoAceita(string $nome): bool
    {
        $ext = strtolower(pathinfo($nome, PATHINFO_EXTENSION));

        return in_array($ext, ImagemProduto::EXTENSOES, true);
    }

    /**
     * Uma consulta só pro lote inteiro. Código de barras compara só os dígitos e também traz quem
     * casa ignorando zeros à esquerda (segunda tentativa, decisão 5); código externo sem
     * diferenciar maiúscula. Produto REJEITADO (self-service do promotor) não casa.
     *
     * @param  list<string>  $codigos
     */
    private static function candidatos(Empresa $empresa, string $chave, array $codigos): Collection
    {
        if ($codigos === []) {
            return collect();
        }

        $query = ProdutoAuditoria::withoutGlobalScopes()
            ->where('empresa_id', $empresa->id)
            ->where(fn ($q) => $q->whereNull('status_aprovacao')->orWhere('status_aprovacao', '!=', StatusAprovacao::REJEITADO->value));

        if ($chave === 'codigo_barras') {
            $semZeros = array_values(array_unique(array_map(fn ($c) => ltrim($c, '0'), $codigos)));
            $query->whereNotNull('codigo_barras')
                ->whereIn(DB::raw("ltrim(regexp_replace(codigo_barras, '\\D', '', 'g'), '0')"), $semZeros);
        } else {
            $query->whereNotNull('codigo_externo')
                ->whereIn(DB::raw('lower(codigo_externo)'), array_values(array_unique(array_map('mb_strtolower', $codigos))));
        }

        return $query->get(['id', 'uuid', 'descricao', 'codigo_barras', 'codigo_externo', 'imagem_url', 'ativo']);
    }

    /** @return array<string, mixed> */
    private static function situacao(?string $codigo, string $nome, string $chave, Collection $candidatos, bool $substituir): array
    {
        if (! self::extensaoAceita($nome)) {
            return ['situacao' => 'formato_invalido', 'mensagem' => 'Formato não aceito: use JPG, PNG ou WEBP.'];
        }
        if ($codigo === null) {
            return ['situacao' => 'nao_encontrado', 'mensagem' => 'O nome do arquivo não tem um código.'];
        }

        if ($chave === 'codigo_barras') {
            $digitos = fn (ProdutoAuditoria $p) => preg_replace('/\D/', '', (string) $p->codigo_barras);
            $achados = $candidatos->filter(fn ($p) => $digitos($p) === $codigo);
            if ($achados->isEmpty()) {
                $achados = $candidatos->filter(fn ($p) => ltrim($digitos($p), '0') === ltrim($codigo, '0'));
            }
        } else {
            $achados = $candidatos->filter(fn ($p) => mb_strtolower((string) $p->codigo_externo) === mb_strtolower($codigo));
        }
        $achados = $achados->values();

        if ($achados->isEmpty()) {
            return ['situacao' => 'nao_encontrado', 'mensagem' => 'Nenhum produto com este código.'];
        }
        if ($achados->count() > 1) {
            return [
                'situacao' => 'ambiguo',
                'mensagem' => 'Mais de um produto com este código.',
                'produtos' => $achados->map(fn ($p) => self::produto($p))->all(),
            ];
        }

        $produto = $achados->first();
        if ($produto->imagem_url && ! $substituir) {
            return ['situacao' => 'ja_tem_imagem', 'mensagem' => 'O produto já tem imagem.', 'produto' => self::produto($produto)];
        }

        return [
            'situacao' => 'ok',
            'mensagem' => $produto->ativo ? null : 'Produto inativo.',
            'produto' => self::produto($produto),
        ];
    }

    /** @return array<string, mixed> */
    private static function produto(ProdutoAuditoria $p): array
    {
        return [
            'id' => $p->uuid,
            'descricao' => $p->descricao,
            'codigo_barras' => $p->codigo_barras,
            'codigo_externo' => $p->codigo_externo,
            'imagem_url' => $p->imagem_url,
            'ativo' => (bool) $p->ativo,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $resultados
     * @return array<string, int>
     */
    private static function resumo(array $resultados): array
    {
        $contagem = array_fill_keys([...self::SITUACOES, 'gravado'], 0);
        foreach ($resultados as $r) {
            $contagem[$r['situacao']]++;
        }

        return $contagem;
    }
}

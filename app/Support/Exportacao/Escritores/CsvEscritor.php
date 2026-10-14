<?php

namespace App\Support\Exportacao\Escritores;

use App\Support\Exportacao\Coluna;
use App\Support\Exportacao\Formatador;

/**
 * CSV que o Excel em pt-BR abre certo (docs/68 §4.2): `;` como separador, BOM UTF-8, CRLF e vírgula
 * decimal. Só dado — o contexto (período, filtros) vai no nome do arquivo e no XLSX, pra o CSV
 * continuar importável sem ginástica.
 */
final class CsvEscritor
{
    /** @var resource */
    private $arquivo;

    /** @param list<Coluna> $colunas */
    public function __construct(string $caminho, private readonly array $colunas, private readonly Formatador $formatador)
    {
        $this->arquivo = fopen($caminho, 'wb');
        fwrite($this->arquivo, "\xEF\xBB\xBF");
        $this->escrever(array_map(fn (Coluna $c) => $c->rotulo, $colunas));
    }

    /** @param array<string, mixed> $linha */
    public function linha(array $linha): void
    {
        $this->escrever(array_map(
            fn (Coluna $c) => $this->formatador->texto($c, $linha[$c->chave] ?? null),
            $this->colunas,
        ));
    }

    public function fechar(): void
    {
        fclose($this->arquivo);
    }

    /** @param list<string> $celulas */
    private function escrever(array $celulas): void
    {
        $saida = array_map(function (string $texto): string {
            return preg_match('/[";\r\n]/', $texto) ? '"'.str_replace('"', '""', $texto).'"' : $texto;
        }, $celulas);
        fwrite($this->arquivo, implode(';', $saida)."\r\n");
    }
}

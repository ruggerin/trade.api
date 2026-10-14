<?php

namespace App\Support\Exportacao\Escritores;

/**
 * Gravador de ZIP mínimo (método "deflate", sem criptografia, sem ZIP64) — o suficiente pra montar
 * um XLSX sem depender da extensão `zip` (que não existe em todo servidor). Cada arquivo entra
 * inteiro na memória; o XLSX do Horus tem teto de linhas, então o tamanho é limitado.
 */
final class ZipMinimo
{
    /** @var list<array{nome: string, crc: int, tamComprimido: int, tamOriginal: int, offset: int}> */
    private array $entradas = [];

    private string $conteudo = '';

    public function adicionar(string $nome, string $dados): void
    {
        $comprimido = gzdeflate($dados, 6);
        if ($comprimido === false) {
            throw new \RuntimeException('Falha ao compactar '.$nome);
        }
        $crc = crc32($dados);
        $offset = strlen($this->conteudo);

        // Cabeçalho local: assinatura, versão 20, flags 0x0800 (nome em UTF-8), método 8, hora/data fixas.
        $this->conteudo .= pack('VvvvvvVVVvv', 0x04034b50, 20, 0x0800, 8, 0, 0x21, $crc, strlen($comprimido), strlen($dados), strlen($nome), 0)
            .$nome.$comprimido;

        $this->entradas[] = ['nome' => $nome, 'crc' => $crc, 'tamComprimido' => strlen($comprimido), 'tamOriginal' => strlen($dados), 'offset' => $offset];
    }

    public function gravar(string $caminho): void
    {
        $diretorio = '';
        foreach ($this->entradas as $e) {
            $diretorio .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0x0800, 8, 0, 0x21, $e['crc'], $e['tamComprimido'], $e['tamOriginal'], strlen($e['nome']), 0, 0, 0, 0, 0, $e['offset']).$e['nome'];
        }
        $fim = pack('VvvvvVVv', 0x06054b50, 0, 0, count($this->entradas), count($this->entradas), strlen($diretorio), strlen($this->conteudo), 0);

        file_put_contents($caminho, $this->conteudo.$diretorio.$fim);
    }
}

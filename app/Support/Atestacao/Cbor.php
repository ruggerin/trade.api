<?php

namespace App\Support\Atestacao;

use RuntimeException;

/**
 * Decodificador CBOR mínimo (RFC 8949) — só o que o objeto de atestação do App Attest usa: inteiros,
 * byte strings, text strings, arrays e maps de tamanho definido. Byte string volta como string PHP
 * binária. Qualquer coisa fora disso lança exceção (atestação malformada = falha).
 */
final class Cbor
{
    private int $pos = 0;

    private function __construct(private readonly string $dados) {}

    public static function decodificar(string $dados): mixed
    {
        $leitor = new self($dados);
        $valor = $leitor->ler();

        if ($leitor->pos !== strlen($dados)) {
            throw new RuntimeException('CBOR com bytes sobrando.');
        }

        return $valor;
    }

    private function ler(): mixed
    {
        $inicial = $this->byte();
        $tipo = $inicial >> 5;
        $info = $inicial & 0x1F;
        $tamanho = $this->argumento($info);

        return match ($tipo) {
            0 => $tamanho,
            1 => -1 - $tamanho,
            2, 3 => $this->bytes($tamanho),
            4 => array_map(fn () => $this->ler(), $tamanho > 0 ? range(1, $tamanho) : []),
            5 => $this->mapa($tamanho),
            default => throw new RuntimeException("Tipo CBOR {$tipo} não suportado."),
        };
    }

    private function mapa(int $tamanho): array
    {
        $mapa = [];
        for ($i = 0; $i < $tamanho; $i++) {
            $chave = $this->ler();
            if (! is_int($chave) && ! is_string($chave)) {
                throw new RuntimeException('Chave CBOR inválida.');
            }
            $mapa[$chave] = $this->ler();
        }

        return $mapa;
    }

    private function argumento(int $info): int
    {
        return match (true) {
            $info < 24 => $info,
            $info === 24 => $this->byte(),
            $info === 25 => unpack('n', $this->bytes(2))[1],
            $info === 26 => unpack('N', $this->bytes(4))[1],
            $info === 27 => unpack('J', $this->bytes(8))[1],
            default => throw new RuntimeException('Tamanho CBOR indefinido não suportado.'),
        };
    }

    private function byte(): int
    {
        return ord($this->bytes(1));
    }

    private function bytes(int $n): string
    {
        if ($n < 0 || $this->pos + $n > strlen($this->dados)) {
            throw new RuntimeException('CBOR truncado.');
        }
        $parte = substr($this->dados, $this->pos, $n);
        $this->pos += $n;

        return $parte;
    }
}

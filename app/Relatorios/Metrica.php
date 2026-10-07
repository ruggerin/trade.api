<?php

namespace App\Relatorios;

use Closure;

/**
 * Métrica nomeada do catálogo de uma entidade (docs/60 §3.1): uma definição única e testada, nunca
 * fórmula livre. A mesma função calcula a linha e o total — o total é a métrica sobre todos os
 * itens, não a soma das linhas (percentual e mediana não somam).
 */
final class Metrica
{
    public const INTEIRO = 'inteiro';

    public const PERCENTUAL = 'percentual';

    public const MINUTOS = 'minutos';

    public const DECIMAL = 'decimal';

    public const MOEDA = 'moeda';

    /** Valor de texto (ex.: a moda de um campo — "Loja A"). */
    public const TEXTO = 'texto';

    /** Lista de {rotulo, quantidade} — quebra que só faz sentido no total (ex.: canceladas por motivo). */
    public const DISTRIBUICAO = 'distribuicao';

    /**
     * @param  Closure  $calcular  fn(Collection $itens, Contexto $ctx): mixed
     */
    public function __construct(
        public readonly string $chave,
        public readonly string $rotulo,
        public readonly string $formato,
        public readonly Closure $calcular,
        public readonly ?string $descricao = null,
        public readonly ?string $grupo = null,
        // Agregação de um campo (contagem distinta da Loja, soma do Preço…): o editor lista o
        // campo, não a métrica, e a operação se escolhe no chip de Valores.
        public readonly ?string $campo = null,
        public readonly ?string $campoRotulo = null,
        public readonly ?string $agregacao = null,
    ) {}

    /** @return array<string, mixed> */
    public function paraCatalogo(): array
    {
        return array_filter([
            'chave' => $this->chave,
            'rotulo' => $this->rotulo,
            'formato' => $this->formato,
            'descricao' => $this->descricao,
            'grupo' => $this->grupo,
            'campo' => $this->campo,
            'campo_rotulo' => $this->campoRotulo,
            'agregacao' => $this->agregacao,
        ], fn ($v) => $v !== null);
    }
}

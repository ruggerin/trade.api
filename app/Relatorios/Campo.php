<?php

namespace App\Relatorios;

use Closure;

/**
 * Campo do catálogo de uma entidade (docs/60 §3.2): filtrável e/ou agrupável. O front só conhece a
 * `chave`; a coluna/relação real fica nos closures, nunca sai do backend.
 */
final class Campo
{
    public const ENUM = 'enum';

    public const RELACAO = 'relacao';

    public const BOOLEANO = 'booleano';

    public const DATA = 'data';

    public const GRANULARIDADES = Periodo::GRANULARIDADES;

    /**
     * @param  list<string>  $operadores  vazio = não filtrável
     * @param  array<string, string>  $opcoes  valor => rótulo (só ENUM)
     * @param  ?string  $fonte  de onde o editor tira as opções de uma RELACAO (ex.: `usuarios`)
     * @param  ?Closure  $filtrar  fn(Builder $q, string $operador, mixed $valor): void
     * @param  ?Closure  $agrupar  fn(Model $item, ?string $granularidade, string $tz): array{0: string, 1: string} (chave, rótulo)
     */
    public function __construct(
        public readonly string $chave,
        public readonly string $rotulo,
        public readonly string $tipo,
        public readonly array $operadores = [],
        public readonly bool $agrupavel = true,
        public readonly array $opcoes = [],
        public readonly ?string $fonte = null,
        public readonly bool $periodo = false,
        public readonly ?Closure $filtrar = null,
        public readonly ?Closure $agrupar = null,
        public readonly ?string $grupo = null,
    ) {}

    public function filtravel(): bool
    {
        return $this->operadores !== [] && $this->filtrar !== null;
    }

    /** @return array<string, mixed> */
    public function paraCatalogo(): array
    {
        return array_filter([
            'chave' => $this->chave,
            'rotulo' => $this->rotulo,
            'tipo' => $this->tipo,
            'operadores' => $this->filtravel() ? $this->operadores : [],
            'agrupavel' => $this->agrupavel,
            'periodo' => $this->periodo,
            'opcoes' => $this->opcoes !== []
                ? collect($this->opcoes)->map(fn (string $rotulo, string $valor) => ['valor' => $valor, 'rotulo' => $rotulo])->values()->all()
                : null,
            'fonte' => $this->fonte,
            'granularidades' => $this->tipo === self::DATA ? self::GRANULARIDADES : null,
            'grupo' => $this->grupo,
        ], fn ($v) => $v !== null);
    }
}

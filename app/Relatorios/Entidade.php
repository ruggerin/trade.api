<?php

namespace App\Relatorios;

use BackedEnum;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Entidade do gerador de relatórios (docs/60 §3.2): a lista fechada do que pode ser filtrado,
 * agrupado e medido. Campo ou métrica novos = uma entrada aqui + teste; nada de migration.
 *
 * A consulta nasce sempre do model (global scope BelongsToEmpresa) — o executor nunca recebe
 * `empresa_id` de fora.
 */
abstract class Entidade
{
    public const OPERADORES = ['em', 'nao_em', 'igual', 'vazio', 'nao_vazio'];

    abstract public function chave(): string;

    abstract public function rotulo(): string;

    /** Consulta base já recortada pelo período, com as relações que os campos/métricas leem. */
    abstract public function consulta(string $campoPeriodo, Carbon $inicio, Carbon $fim): Builder;

    /** @return array<string, Campo> */
    abstract public function campos(): array;

    /** @return array<string, Metrica> */
    abstract protected function metricasProprias(): array;

    /**
     * Métricas da entidade + as que valem pra qualquer campo agrupável, como no Power BI:
     * contagem distinta (quantos valores diferentes) e moda (o valor que mais aparece).
     *
     * @return array<string, Metrica>
     */
    public function metricas(): array
    {
        $derivadas = [];
        foreach ($this->campos() as $campo) {
            if (! $campo->agrupavel || $campo->agrupar === null) {
                continue;
            }
            $ehData = $campo->tipo === Campo::DATA;
            // Data conta dias distintos; o nível vem fixo em "dia".
            $valores = fn (Collection $itens, Contexto $ctx) => $itens
                ->map(fn ($item) => ($campo->agrupar)($item, $ehData ? 'dia' : null, $ctx->tz))
                ->reject(fn (array $chaveRotulo) => $chaveRotulo[0] === '-');

            $derivadas[] = new Metrica(
                'contagem_distinta:'.$campo->chave,
                'Contagem distinta de '.$campo->rotulo.($ehData ? ' (dias)' : ''),
                Metrica::INTEIRO,
                fn (Collection $itens, Contexto $ctx) => $valores($itens, $ctx)->unique(fn (array $cr) => $cr[0])->count(),
                'Quantos valores diferentes aparecem.',
                grupo: $campo->grupo,
                campo: $campo->chave,
                campoRotulo: $campo->rotulo,
                agregacao: 'contagem_distinta',
            );
            if (! $ehData) {
                $derivadas[] = new Metrica(
                    'moda:'.$campo->chave,
                    'Moda de '.$campo->rotulo,
                    Metrica::TEXTO,
                    fn (Collection $itens, Contexto $ctx) => self::moda($valores($itens, $ctx)->map(fn (array $cr) => $cr[1])),
                    'O valor que mais aparece.',
                    grupo: $campo->grupo,
                    campo: $campo->chave,
                    campoRotulo: $campo->rotulo,
                    agregacao: 'moda',
                );
            }
        }

        return $this->metricasProprias() + collect($derivadas)->keyBy(fn (Metrica $m) => $m->chave)->all();
    }

    /**
     * Valor mais frequente; no empate, o que vem primeiro em ordem alfabética (resultado estável).
     * Null quando não há valor nenhum.
     */
    public static function moda(Collection $valores): string|int|float|null
    {
        if ($valores->isEmpty()) {
            return null;
        }
        $contagem = $valores->map(fn ($v) => (string) $v)->countBy();
        $maximo = $contagem->max();
        $primeiro = $contagem->filter(fn (int $n) => $n === $maximo)->keys()->map(fn ($k) => (string) $k)->sort(SORT_NATURAL | SORT_FLAG_CASE)->first();

        return $valores->first(fn ($v) => (string) $v === $primeiro);
    }

    public function campo(string $chave): ?Campo
    {
        return $this->campos()[$chave] ?? null;
    }

    public function campoPeriodoPadrao(): string
    {
        return collect($this->campos())->first(fn (Campo $c) => $c->periodo)->chave;
    }

    /** @return array<string, mixed> */
    public function catalogo(): array
    {
        return [
            'chave' => $this->chave(),
            'rotulo' => $this->rotulo(),
            'campo_periodo_padrao' => $this->campoPeriodoPadrao(),
            'campos' => collect($this->campos())->map(fn (Campo $c) => $c->paraCatalogo())->values()->all(),
            'metricas' => collect($this->metricas())->map(fn (Metrica $m) => $m->paraCatalogo())->values()->all(),
            'presets' => Periodo::PRESETS,
            'comparacoes' => Periodo::COMPARACOES,
        ];
    }

    // ——— Ajudantes pra declarar campos ———

    /** Filtro sobre coluna de enum/valor simples. `nao_em` inclui o vazio (null não é "nenhum dos valores" no SQL). */
    protected static function filtroColuna(string $coluna): \Closure
    {
        return function (Builder $q, string $operador, mixed $valor) use ($coluna): void {
            match ($operador) {
                'em' => $q->whereIn($coluna, (array) $valor),
                'nao_em' => $q->where(fn (Builder $s) => $s->whereNotIn($coluna, (array) $valor)->orWhereNull($coluna)),
                'vazio' => $q->whereNull($coluna),
                'nao_vazio' => $q->whereNotNull($coluna),
                default => null,
            };
        };
    }

    /**
     * Filtro sobre FK por uuid do relacionado. A subconsulta passa pelo global scope do model
     * relacionado — uuid de outra empresa simplesmente não casa.
     *
     * @param  class-string<Model>  $modelo
     */
    protected static function filtroRelacao(string $coluna, string $modelo): \Closure
    {
        return function (Builder $q, string $operador, mixed $valor) use ($coluna, $modelo): void {
            $ids = fn () => $modelo::query()->whereIn('uuid', (array) $valor)->select('id');
            match ($operador) {
                'em' => $q->whereIn($coluna, $ids()),
                'nao_em' => $q->where(fn (Builder $s) => $s->whereNotIn($coluna, $ids())->orWhereNull($coluna)),
                'vazio' => $q->whereNull($coluna),
                'nao_vazio' => $q->whereNotNull($coluna),
                default => null,
            };
        };
    }

    /** Chave/rótulo de agrupamento de um relacionado com uuid (null = "Sem …"). */
    protected static function grupoDe(?Model $relacionado, string $atributoRotulo, string $vazio): array
    {
        return $relacionado ? [$relacionado->uuid, (string) $relacionado->{$atributoRotulo}] : ['-', $vazio];
    }

    /** Valor cru de um atributo que pode ser enum ou string. */
    protected static function valor(mixed $v): ?string
    {
        return $v instanceof BackedEnum ? (string) $v->value : ($v === null ? null : (string) $v);
    }

    protected static function percentual(int $parte, int $total): ?int
    {
        return $total > 0 ? (int) round($parte / $total * 100) : null;
    }

    /**
     * Quebra de contagem por rótulo, maior primeiro.
     *
     * @param  callable(mixed): array{0: string, 1: string}  $chaveRotulo
     * @return list<array{chave: string, rotulo: string, quantidade: int}>
     */
    protected static function distribuicao(Collection $itens, callable $chaveRotulo): array
    {
        return $itens
            ->map(fn ($i) => $chaveRotulo($i))
            ->groupBy(fn (array $cr) => $cr[0])
            ->map(fn (Collection $g, string $chave) => ['chave' => $chave, 'rotulo' => $g->first()[1], 'quantidade' => $g->count()])
            ->sortByDesc('quantidade')
            ->values()
            ->all();
    }
}

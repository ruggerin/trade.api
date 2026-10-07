<?php

namespace App\Relatorios;

use App\Models\TipoRegistro;
use App\Relatorios\Entidades\OrdemServicoEntidade;
use App\Relatorios\Entidades\RegistroEntidade;
use App\Relatorios\Entidades\VisitaEntidade;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Executa a definição declarativa de um relatório (docs/60 §3) contra uma entidade do catálogo.
 * Toda chave vinda do front (campo, operador, métrica, granularidade) é validada contra a lista
 * fechada da entidade antes de virar query; valores vão sempre por binding.
 *
 * Agregação em memória, como os relatórios fixos: o recorte é sempre um período curto (máx. 92
 * dias) de uma empresa só.
 */
final class ExecutorRelatorio
{
    public const MAX_AGRUPAMENTOS = 3;

    public const MAX_REGRAS = 20;

    public const MAX_METRICAS = 20;

    public const MAX_LINHAS = 5000;

    /** Teto de itens carregados por execução — um ano de uma empresa grande pede filtro. */
    public const MAX_ITENS = 200000;

    /** Como o resultado é desenhado — só apresentação, o cálculo é o mesmo. */
    public const VISUAIS = ['tabela', 'barra', 'linha', 'pizza'];

    /** @return array<string, Entidade> */
    public static function entidades(): array
    {
        return [
            'ordem_servico' => new OrdemServicoEntidade,
            'visita' => new VisitaEntidade,
            'registro' => new RegistroEntidade,
        ];
    }

    /**
     * A entidade da definição. Registro com `formulario` (uuid de TipoRegistro, docs/60 §3.2) ganha
     * as perguntas daquele formulário como campos e métricas; o formulário é buscado pelo global
     * scope de empresa — de outra empresa simplesmente não existe.
     */
    public static function entidade(string $chave, ?string $formulario = null): ?Entidade
    {
        if ($chave === 'registro' && $formulario) {
            $tipo = TipoRegistro::query()->with('campos')->where('uuid', $formulario)->first();
            if (! $tipo) {
                throw ValidationException::withMessages(['definicao.formulario' => 'Formulário não encontrado.']);
            }

            return new RegistroEntidade($tipo);
        }

        return self::entidades()[$chave] ?? null;
    }

    /**
     * Valida e normaliza uma definição. Lança ValidationException (422) com a chave do problema.
     *
     * @return array<string, mixed>
     */
    public static function validar(mixed $definicao): array
    {
        if (! is_array($definicao)) {
            throw ValidationException::withMessages(['definicao' => 'Definição inválida.']);
        }

        Validator::make($definicao, [
            'entidade' => ['required', 'string', Rule::in(array_keys(self::entidades()))],
            'formulario' => ['nullable', 'string', 'uuid'],
        ])->validate();

        if ($definicao['entidade'] !== 'registro') {
            unset($definicao['formulario']);
        }
        $entidade = self::entidade($definicao['entidade'], $definicao['formulario'] ?? null);
        $campos = $entidade->campos();
        $filtraveis = collect($campos)->filter(fn (Campo $c) => $c->filtravel())->keys()->all();
        $agrupaveis = collect($campos)->filter(fn (Campo $c) => $c->agrupavel)->keys()->all();
        $periodos = collect($campos)->filter(fn (Campo $c) => $c->periodo)->keys()->all();
        $metricas = array_keys($entidade->metricas());

        $dados = Validator::make($definicao, [
            'entidade' => ['required', 'string'],
            'formulario' => ['nullable', 'string'],
            'periodo' => ['nullable', 'array'],
            'periodo.campo' => ['nullable', 'string', Rule::in($periodos)],
            'periodo.preset' => ['nullable', 'string', Rule::in(Periodo::PRESETS)],
            'periodo.inicio' => ['nullable', 'date_format:Y-m-d', 'required_with:periodo.fim'],
            'periodo.fim' => ['nullable', 'date_format:Y-m-d', 'required_with:periodo.inicio', 'after_or_equal:periodo.inicio'],
            'filtros' => ['nullable', 'array'],
            'filtros.combinador' => ['nullable', 'string', Rule::in(['E', 'OU'])],
            'filtros.regras' => ['nullable', 'array', 'max:'.self::MAX_REGRAS],
            'filtros.regras.*.campo' => ['required', 'string', Rule::in($filtraveis)],
            'filtros.regras.*.operador' => ['required', 'string', Rule::in(Entidade::OPERADORES)],
            'filtros.regras.*.valor' => ['nullable'],
            // Filtros rápidos da tela do relatório: valem só naquela execução, sempre com E.
            'filtros_rapidos' => ['nullable', 'array', 'max:'.self::MAX_REGRAS],
            'filtros_rapidos.*.campo' => ['required', 'string', Rule::in($filtraveis)],
            'filtros_rapidos.*.operador' => ['required', 'string', Rule::in(Entidade::OPERADORES)],
            'filtros_rapidos.*.valor' => ['nullable'],
            'agrupar' => ['nullable', 'array', 'max:'.self::MAX_AGRUPAMENTOS],
            'agrupar.*.campo' => ['required', 'string', Rule::in($agrupaveis)],
            'agrupar.*.granularidade' => ['nullable', 'string', Rule::in(Campo::GRANULARIDADES)],
            // Matriz (pivot): o agrupamento vai pras linhas ou vira colunas.
            'agrupar.*.eixo' => ['nullable', 'string', Rule::in(['linha', 'coluna'])],
            'visual' => ['nullable', 'string', Rule::in(self::VISUAIS)],
            'metricas' => ['required', 'array', 'min:1', 'max:'.self::MAX_METRICAS],
            'metricas.*.chave' => ['required', 'string', 'distinct', Rule::in($metricas)],
            'comparar' => ['nullable', 'string', Rule::in(Periodo::COMPARACOES)],
            'ordenar' => ['nullable', 'array'],
            'ordenar.chave' => ['required_with:ordenar', 'string'],
            'ordenar.direcao' => ['nullable', 'string', Rule::in(['asc', 'desc'])],
            'grafico' => ['nullable', 'array'],
            'grafico.tipo' => ['required_with:grafico', 'string', Rule::in(['barra', 'linha', 'pizza'])],
            'grafico.eixo_x' => ['nullable', 'string'],
            'grafico.metrica' => ['nullable', 'string'],
        ])->validate();

        // Operador e valor de cada regra contra o próprio campo (o Rule::in acima só garante que o
        // operador existe em algum campo).
        foreach (['filtros.regras' => $dados['filtros']['regras'] ?? [], 'filtros_rapidos' => $dados['filtros_rapidos'] ?? []] as $onde => $regras) {
            foreach ($regras as $i => $regra) {
                self::validarRegra($campos[$regra['campo']], $regra, "definicao.$onde.$i");
            }
        }

        foreach ($dados['agrupar'] ?? [] as $i => $grupo) {
            $campo = $campos[$grupo['campo']];
            if ($campo->tipo === Campo::DATA && empty($grupo['granularidade'])) {
                $dados['agrupar'][$i]['granularidade'] = 'dia';
            }
            if ($campo->tipo !== Campo::DATA) {
                unset($dados['agrupar'][$i]['granularidade']);
            }
            $dados['agrupar'][$i]['eixo'] = $grupo['eixo'] ?? 'linha';
        }

        // A mesma data pode entrar duas vezes em níveis diferentes (mês nas linhas × ano nas
        // colunas); aí a dimensão passa a se chamar "campo:granularidade" pra não colidir.
        $pares = array_map(fn (array $g) => $g['campo'].'|'.($g['granularidade'] ?? ''), $dados['agrupar'] ?? []);
        if (count($pares) !== count(array_unique($pares))) {
            throw ValidationException::withMessages(['definicao.agrupar' => 'Esse campo já está no relatório.']);
        }
        $vezes = array_count_values(array_column($dados['agrupar'] ?? [], 'campo'));
        foreach ($dados['agrupar'] ?? [] as $i => $grupo) {
            $dados['agrupar'][$i]['chave'] = $vezes[$grupo['campo']] > 1 ? $grupo['campo'].':'.$grupo['granularidade'] : $grupo['campo'];
        }
        $eixos = array_column($dados['agrupar'] ?? [], 'eixo');
        if ($eixos !== [] && ! in_array('linha', $eixos, true)) {
            throw ValidationException::withMessages(['definicao.agrupar' => 'Coloque ao menos um campo nas linhas.']);
        }

        $chavesOrdenaveis = array_merge(array_column($dados['agrupar'] ?? [], 'chave'), array_column($dados['metricas'], 'chave'));
        if (isset($dados['ordenar']) && ! in_array($dados['ordenar']['chave'], $chavesOrdenaveis, true)) {
            throw ValidationException::withMessages(['definicao.ordenar.chave' => 'Ordene por um campo agrupado ou uma métrica do relatório.']);
        }

        $dados['periodo'] = array_filter([
            'campo' => $dados['periodo']['campo'] ?? $entidade->campoPeriodoPadrao(),
            'preset' => $dados['periodo']['preset'] ?? (empty($dados['periodo']['inicio']) ? 'ultimos_7_dias' : null),
            'inicio' => $dados['periodo']['inicio'] ?? null,
            'fim' => $dados['periodo']['fim'] ?? null,
        ]);
        $dados['filtros'] = [
            'combinador' => $dados['filtros']['combinador'] ?? 'E',
            'regras' => array_values($dados['filtros']['regras'] ?? []),
        ];
        $dados['agrupar'] = array_values($dados['agrupar'] ?? []);
        $dados['filtros_rapidos'] = array_values($dados['filtros_rapidos'] ?? []);
        if ($dados['filtros_rapidos'] === []) {
            unset($dados['filtros_rapidos']);
        }
        $dados['comparar'] = $dados['comparar'] ?? null;
        $dados['visual'] = $dados['visual'] ?? 'tabela';
        if (empty($dados['formulario'])) {
            unset($dados['formulario']);
        }

        return $dados;
    }

    /** Operador aceito pelo campo e valor no formato certo (lista, booleano ou nada). */
    private static function validarRegra(Campo $campo, array $regra, string $onde): void
    {
        if (! in_array($regra['operador'], $campo->operadores, true)) {
            throw ValidationException::withMessages(["$onde.operador" => "Operador não aceito para {$campo->rotulo}."]);
        }
        $valor = $regra['valor'] ?? null;
        if (in_array($regra['operador'], ['em', 'nao_em'], true)) {
            if (! is_array($valor) || $valor === [] || count(array_filter($valor, fn ($v) => ! is_string($v))) > 0) {
                throw ValidationException::withMessages(["$onde.valor" => "Escolha ao menos um valor para {$campo->rotulo}."]);
            }
            if ($campo->tipo === Campo::ENUM && array_diff($valor, array_map('strval', array_keys($campo->opcoes))) !== []) {
                throw ValidationException::withMessages(["$onde.valor" => "Valor fora da lista para {$campo->rotulo}."]);
            }
        }
        if ($regra['operador'] === 'igual' && ! is_bool($valor) && ! in_array($valor, ['true', 'false', 0, 1, '0', '1'], true)) {
            throw ValidationException::withMessages(["$onde.valor" => "Valor inválido para {$campo->rotulo}."]);
        }
    }

    /**
     * @param  array<string, mixed>  $definicao  já validada (validar())
     * @return array<string, mixed>
     */
    public static function executar(array $definicao, string $tz): array
    {
        $entidade = self::entidade($definicao['entidade'], $definicao['formulario'] ?? null);
        $ctx = new Contexto(now(), $tz);
        [$inicio, $fim] = Periodo::resolver($definicao['periodo'], $tz);

        $itens = self::itens($entidade, $definicao, $inicio, $fim);
        $metricas = collect($definicao['metricas'])->map(fn (array $m) => $entidade->metricas()[$m['chave']]);
        $campos = collect($definicao['agrupar'])->map(fn (array $g) => [
            'campo' => $entidade->campo($g['campo']),
            'chave' => $g['chave'] ?? $g['campo'],
            'granularidade' => $g['granularidade'] ?? null,
            'eixo' => $g['eixo'] ?? 'linha',
        ]);

        $calcular = fn (Collection $grupo) => $metricas->mapWithKeys(fn (Metrica $m) => [$m->chave => ($m->calcular)($grupo, $ctx)])->all();

        $linhas = self::ordenar(self::agrupar($itens, $campos, $tz, $calcular), $definicao, $campos);
        $truncado = $linhas->count() > self::MAX_LINHAS;

        $resposta = [
            'definicao_resolvida' => $definicao,
            'periodo' => Periodo::local($inicio, $fim, $tz) + ['fuso' => $tz],
            'colunas' => array_merge(
                $campos->map(fn (array $g) => [
                    'chave' => $g['chave'],
                    // Data mostra o nível: "Prazo da visita · Mês".
                    'rotulo' => $g['granularidade'] ? $g['campo']->rotulo.' · '.Periodo::ROTULOS_GRANULARIDADE[$g['granularidade']] : $g['campo']->rotulo,
                    'tipo' => 'dimensao',
                ])->all(),
                $metricas->map(fn (Metrica $m) => ['chave' => $m->chave, 'rotulo' => $m->rotulo, 'tipo' => $m->formato])->values()->all(),
            ),
            'linhas' => $linhas->take(self::MAX_LINHAS)->values()->all(),
            'linhas_truncadas' => $truncado,
            'totais' => $calcular($itens),
        ];

        // Matriz: total de cada linha (somando as colunas) e de cada coluna, calculados sobre os
        // itens — percentual e mediana não se somam no front.
        $doEixo = fn (string $eixo) => $campos->filter(fn (array $g) => $g['eixo'] === $eixo)->values();
        if ($doEixo('coluna')->isNotEmpty()) {
            $resposta['subtotais'] = [
                'linhas' => self::ordenar(self::agrupar($itens, $doEixo('linha'), $tz, $calcular), $definicao, $doEixo('linha'))->take(self::MAX_LINHAS)->values()->all(),
                // Colunas de data da esquerda pra direita no tempo (2025, 2026…).
                'colunas' => self::ordenar(self::agrupar($itens, $doEixo('coluna'), $tz, $calcular), [], $doEixo('coluna'), datasCrescente: true)->values()->all(),
            ];
        }

        if ($definicao['comparar']) {
            [$compInicio, $compFim] = Periodo::comparacao($inicio, $fim, $tz, $definicao['comparar']);
            $resposta['comparativo'] = [
                'tipo' => $definicao['comparar'],
                'periodo' => Periodo::local($compInicio, $compFim, $tz),
                'totais' => $calcular(self::itens($entidade, $definicao, $compInicio, $compFim)),
            ];
        }

        return $resposta;
    }

    /** Agrupa os itens pelos campos e calcula as métricas de cada grupo. */
    private static function agrupar(Collection $itens, Collection $campos, string $tz, \Closure $calcular): Collection
    {
        if ($campos->isEmpty()) {
            return collect();
        }

        return $itens
            ->groupBy(fn ($item) => $campos->map(fn (array $g) => (($g['campo']->agrupar)($item, $g['granularidade'], $tz))[0])->implode('|'))
            ->map(function (Collection $grupo) use ($campos, $tz, $calcular) {
                $primeiro = $grupo->first();
                $dimensoes = $campos->mapWithKeys(function (array $g) use ($primeiro, $tz) {
                    [$chave, $rotulo] = ($g['campo']->agrupar)($primeiro, $g['granularidade'], $tz);

                    return [$g['chave'] => ['chave' => $chave, 'rotulo' => $rotulo]];
                })->all();

                return ['dimensoes' => $dimensoes, 'valores' => $calcular($grupo)];
            })
            ->values();
    }

    private static function itens(Entidade $entidade, array $definicao, Carbon $inicio, Carbon $fim): Collection
    {
        $query = $entidade->consulta($definicao['periodo']['campo'], $inicio, $fim);
        $regras = $definicao['filtros']['regras'];

        foreach ($definicao['filtros_rapidos'] ?? [] as $regra) {
            $query->where(fn (Builder $sub) => ($entidade->campo($regra['campo'])->filtrar)($sub, $regra['operador'], $regra['valor'] ?? null));
        }

        if ($regras !== []) {
            $ou = $definicao['filtros']['combinador'] === 'OU';
            $query->where(function (Builder $q) use ($regras, $entidade, $ou): void {
                foreach ($regras as $regra) {
                    $aplicar = fn (Builder $sub) => ($entidade->campo($regra['campo'])->filtrar)($sub, $regra['operador'], $regra['valor'] ?? null);
                    $ou ? $q->orWhere($aplicar) : $q->where($aplicar);
                }
            });
        }

        if ((clone $query)->count() > self::MAX_ITENS) {
            throw ValidationException::withMessages(['periodo' => 'Dados demais para esse período. Use um período menor ou filtre por rede, loja ou promotor.']);
        }

        return $query->get();
    }

    /** Ordem pedida; sem ela, datas da mais recente pra mais antiga e o resto por rótulo. */
    private static function ordenar(Collection $linhas, array $definicao, Collection $campos, bool $datasCrescente = false): Collection
    {
        $chavesDosCampos = $campos->map(fn (array $g) => $g['chave'])->all();
        $pedida = $definicao['ordenar']['chave'] ?? null;
        $ordenavel = $pedida !== null && (in_array($pedida, $chavesDosCampos, true) || in_array($pedida, array_column($definicao['metricas'] ?? [], 'chave'), true));
        if ($ordenavel) {
            $chave = $pedida;
            $desc = ($definicao['ordenar']['direcao'] ?? 'asc') === 'desc';
            $valor = fn (array $l) => array_key_exists($chave, $l['dimensoes']) ? $l['dimensoes'][$chave]['chave'] : ($l['valores'][$chave] ?? null);

            return $linhas->sort(fn ($a, $b) => $desc ? $valor($b) <=> $valor($a) : $valor($a) <=> $valor($b))->values();
        }

        return $linhas->sort(function (array $a, array $b) use ($campos, $datasCrescente) {
            foreach ($campos as $g) {
                $campo = $g['campo'];
                $ca = $a['dimensoes'][$g['chave']];
                $cb = $b['dimensoes'][$g['chave']];
                $crescente = $datasCrescente || in_array($g['granularidade'], Periodo::CICLICAS, true);
                $cmp = $campo->tipo === Campo::DATA
                    ? ($crescente ? strcmp($ca['chave'], $cb['chave']) : strcmp($cb['chave'], $ca['chave']))
                    : strcasecmp($ca['rotulo'], $cb['rotulo']);
                if ($cmp !== 0) {
                    return $cmp;
                }
            }

            return 0;
        })->values();
    }
}

<?php

namespace App\Support\Exportacao;

use App\Enums\Permissao;
use App\Models\Usuario;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;

/**
 * Uma rotina exportável (docs/68 §4.1). A regra de ouro é "o arquivo é o que a tela mostra": em vez
 * de reescrever a consulta, a rotina CHAMA o mesmo controller da tela, com os mesmos filtros e o
 * mesmo usuário — escopo de empresa, de perfil e valores sensíveis já vêm resolvidos de lá.
 */
abstract class Rotina
{
    abstract public static function chave(): string;

    abstract public function titulo(): string;

    /** Permissão de tela que libera a exportação (a mesma que libera a tela). */
    abstract public function permissao(): Permissao;

    /** Chamado antes de `colunas()`: rotina cujas colunas dependem do filtro (ex.: agrupar) ajusta aqui. */
    public function configurar(array $filtros): void
    {
    }

    /** @return list<Coluna> */
    abstract public function colunas(): array;

    /**
     * Filtros que o front pode mandar. Qualquer outra chave é descartada — a exportação não é um
     * caminho pra passar parâmetro que a tela não passaria (ex.: `por_pagina`, `empresa_uuid`).
     *
     * @return list<string>
     */
    abstract public function filtrosPermitidos(): array;

    /**
     * Linhas já no formato `[chave da coluna => valor bruto]`.
     *
     * @param  array<string, mixed>  $filtros
     * @return iterable<array<string, mixed>>
     */
    abstract public function linhas(array $filtros, Usuario $usuario): iterable;

    /**
     * Chama `$controller@$metodo` página a página (até 200 por vez) e entrega cada item.
     *
     * @param  array<string, mixed>  $filtros
     * @return \Generator<int, array<string, mixed>>
     */
    protected function paginar(string $controller, string $metodo, string $chaveItens, array $filtros, Usuario $usuario): \Generator
    {
        $pagina = 1;
        do {
            $dados = $this->chamar($controller, $metodo, $filtros + ['por_pagina' => 200, 'page' => $pagina], $usuario);
            foreach ($dados[$chaveItens] ?? [] as $item) {
                yield $item;
            }
            $ultima = (int) ($dados['meta']['last_page'] ?? 1);
            $pagina++;
        } while ($pagina <= $ultima);
    }

    /**
     * Executa o método do controller como se fosse a requisição da tela. O paginador do Laravel lê
     * `page` do request GLOBAL, então o request do container é trocado durante a chamada.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    protected function chamar(string $controller, string $metodo, array $params, Usuario $usuario): array
    {
        $original = app('request');
        $fake = Request::create('/', 'GET', $params);
        $fake->setUserResolver(fn () => $usuario);

        app()->instance('request', $fake);
        Paginator::currentPageResolver(fn () => (int) $fake->input('page', 1));

        try {
            $resposta = app()->call([app($controller), $metodo], ['request' => $fake]);
        } finally {
            app()->instance('request', $original);
            Paginator::currentPageResolver(fn () => (int) $original->input('page', 1));
        }

        if (! $resposta instanceof JsonResponse) {
            throw new \RuntimeException('A rotina de exportação esperava JSON de '.$controller.'::'.$metodo);
        }

        return $resposta->getData(true);
    }

    /** Pega um valor aninhado de um array ("a.b.c"); null se qualquer nível faltar. */
    protected static function pegar(array $dados, string $caminho): mixed
    {
        return data_get($dados, $caminho);
    }
}

<?php

namespace App\Http\Controllers\Integracao;

use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureIntegracao;
use App\Models\DepartamentoAuditoria;
use App\Models\Empresa;
use App\Models\MarcaAuditoria;
use App\Models\PontoVenda;
use App\Models\ProdutoAuditoria;
use App\Models\RamoAtividade;
use App\Models\RedeLoja;
use App\Models\SecaoAuditoria;
use App\Models\Supervisor;
use App\Models\Vendedor;
use App\Support\CadastroApoioErp;
use App\Support\CarteiraVendedores;
use App\Support\EquipeComercial;
use App\Support\ImportacaoPontosVenda;
use App\Support\ImportacaoProdutos;
use App\Support\ImportacaoSortimento;
use App\Support\LeitorCsv;
use App\Support\LoteIntegracao;
use App\Support\PedidosErp;
use App\Support\ProblemaIntegracao;
use App\Support\PromotoresErp;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Escrita da API de integração (docs/96 §7.2), pro ERP do cliente. Todas as rotas seguem o mesmo
 * contrato:
 *
 * - `PUT` com `{"itens": [...]}`: upsert em lote pelo código do ERP (`codigo_externo`; nos cadastros
 *   de apoio, o nome). Cria o que não existe, atualiza o que existe, campo ausente não apaga nada.
 * - Resultado item a item (`criado`, `atualizado`, `erro` + motivo). Um item ruim não derruba o
 *   lote; erro que vale pro lote todo (limite do plano) recusa tudo com 422.
 * - `?simular=1` valida e devolve o mesmo resultado sem gravar.
 * - `Idempotency-Key` (middleware `idempotencia`): reenvio devolve a mesma resposta.
 * - As regras são as das importações por planilha (App\Support\Importacao*, EquipeComercial,
 *   CarteiraVendedores), pra API e planilha nunca darem resultado diferente.
 *
 * Ordem certa numa carga inicial: redes/ramos → lojas; departamentos → seções, marcas → produtos;
 * supervisores → vendedores → carteira; lojas + produtos → mix; supervisores → promotores;
 * lojas → pedidos → entregas.
 */
class EscritaController extends Controller
{
    public function lojas(Request $request): JsonResponse
    {
        $empresa = $this->empresa($request);
        // A carteira da loja aceita lista (["374", "680"]) além do texto da planilha ("374|680").
        $itens = array_map(function ($item) {
            if (is_array($item) && is_array($item['vendedores'] ?? null)) {
                $item['vendedores'] = implode('|', array_map(fn ($v) => (string) $v, $item['vendedores']));
            }

            return $item;
        }, $this->itens($request));

        return $this->lote(
            $request, $itens, ImportacaoPontosVenda::COLUNAS,
            fn ($d) => $d['codigo_externo'] ?? null,
            fn ($codigos) => $this->porCodigo(PontoVenda::class, $empresa, $codigos),
            fn ($linhas, $simular) => ImportacaoPontosVenda::aplicar($empresa, $linhas, $simular),
        );
    }

    public function redes(Request $request): JsonResponse
    {
        return $this->apoio($request, RedeLoja::class, CadastroApoioErp::COLUNAS, 'redes');
    }

    public function ramos(Request $request): JsonResponse
    {
        return $this->apoio($request, RamoAtividade::class, CadastroApoioErp::COLUNAS, 'ramos');
    }

    public function produtos(Request $request): JsonResponse
    {
        $empresa = $this->empresa($request);

        return $this->lote(
            $request, $this->itens($request), ImportacaoProdutos::COLUNAS,
            fn ($d) => $d['codigo_externo'] ?? null,
            fn ($codigos) => $this->porCodigo(ProdutoAuditoria::class, $empresa, $codigos),
            fn ($linhas, $simular) => ImportacaoProdutos::aplicar($empresa, $linhas, $simular),
        );
    }

    public function marcas(Request $request): JsonResponse
    {
        return $this->apoio($request, MarcaAuditoria::class, CadastroApoioErp::COLUNAS_MARCA, 'marcas');
    }

    public function departamentos(Request $request): JsonResponse
    {
        return $this->apoio($request, DepartamentoAuditoria::class, CadastroApoioErp::COLUNAS, 'departamentos');
    }

    public function secoes(Request $request): JsonResponse
    {
        $empresa = $this->empresa($request);

        return $this->lote(
            $request, $this->itens($request), CadastroApoioErp::COLUNAS_SECAO,
            fn ($d) => isset($d['descricao']) ? (($d['departamento'] ?? '').' › '.$d['descricao']) : null,
            function ($chaves) use ($empresa) {
                $existentes = SecaoAuditoria::withoutGlobalScopes()->where('secoes_auditoria.empresa_id', $empresa->id)
                    ->join('departamentos_auditoria as d', 'd.id', '=', 'secoes_auditoria.departamento_id')
                    ->get(['secoes_auditoria.descricao', 'd.descricao as departamento'])
                    ->mapWithKeys(fn ($s) => [LeitorCsv::chaveNome($s->departamento).' › '.LeitorCsv::chaveNome($s->descricao) => true]);

                return collect($chaves)->filter(function ($c) use ($existentes) {
                    [$dep, $nome] = explode(' › ', $c, 2) + [1 => ''];

                    return $existentes->has(LeitorCsv::chaveNome($dep).' › '.LeitorCsv::chaveNome($nome));
                })->mapWithKeys(fn ($c) => [$c => true])->all();
            },
            fn ($linhas, $simular) => CadastroApoioErp::secoes($empresa, $linhas, $simular),
        );
    }

    /** Mix da loja (sortimento): aditivo; `substituir: true` deixa cada loja do lote só com o que veio. */
    public function mix(Request $request): JsonResponse
    {
        $empresa = $this->empresa($request);
        $request->validate(['substituir' => ['sometimes', 'boolean']]);
        $substituir = $request->boolean('substituir');

        return $this->lote(
            $request, $this->itens($request, ImportacaoSortimento::MAX_LINHAS), ImportacaoSortimento::COLUNAS,
            fn ($d) => isset($d['codigo_externo_loja'], $d['codigo_externo_produto']) ? "{$d['codigo_externo_loja']} × {$d['codigo_externo_produto']}" : null,
            function ($chaves) use ($empresa) {
                return DB::table('sortimentos_ponto_venda as s')
                    ->join('pontos_venda as pv', 'pv.id', '=', 's.ponto_venda_id')
                    ->join('produtos_auditoria as p', 'p.id', '=', 's.produto_id')
                    ->where('pv.empresa_id', $empresa->id)
                    ->whereIn(DB::raw("pv.codigo_externo || ' × ' || p.codigo_externo"), $chaves)
                    ->selectRaw("pv.codigo_externo || ' × ' || p.codigo_externo as chave")
                    ->pluck('chave')
                    ->mapWithKeys(fn ($c) => [$c => true])->all();
            },
            fn ($linhas, $simular) => ImportacaoSortimento::aplicar($empresa, $linhas, $simular, $substituir),
        );
    }

    public function supervisores(Request $request): JsonResponse
    {
        $empresa = $this->empresa($request);

        return $this->lote(
            $request, $this->itens($request, EquipeComercial::MAX_LINHAS), EquipeComercial::COLUNAS_SUPERVISOR,
            fn ($d) => $d['codigo_externo'] ?? null,
            fn ($codigos) => $this->porCodigo(Supervisor::class, $empresa, $codigos),
            fn ($linhas, $simular) => EquipeComercial::supervisores($empresa, $linhas, tudoOuNada: false, simular: $simular),
        );
    }

    public function vendedores(Request $request): JsonResponse
    {
        $empresa = $this->empresa($request);

        return $this->lote(
            $request, $this->itens($request, EquipeComercial::MAX_LINHAS), [...EquipeComercial::COLUNAS_VENDEDOR, 'codigo_supervisor'],
            fn ($d) => $d['codigo_externo'] ?? null,
            fn ($codigos) => $this->porCodigo(Vendedor::class, $empresa, $codigos),
            fn ($linhas, $simular) => EquipeComercial::vendedores($empresa, $linhas, tudoOuNada: false, simular: $simular),
        );
    }

    /** Carteira vendedor × loja: cada loja do lote fica exatamente com os vendedores listados pra ela. */
    public function carteira(Request $request): JsonResponse
    {
        $empresa = $this->empresa($request);

        return $this->lote(
            $request, $this->itens($request, CarteiraVendedores::MAX_LINHAS), ['codigo_externo_loja', 'codigo_externo_vendedor'],
            fn ($d) => isset($d['codigo_externo_loja'], $d['codigo_externo_vendedor']) ? "{$d['codigo_externo_loja']} × {$d['codigo_externo_vendedor']}" : null,
            function ($chaves) use ($empresa) {
                return DB::table('vendedor_ponto_venda as c')
                    ->join('pontos_venda as pv', 'pv.id', '=', 'c.ponto_venda_id')
                    ->join('vendedores as v', 'v.id', '=', 'c.vendedor_id')
                    ->where('pv.empresa_id', $empresa->id)
                    ->whereIn(DB::raw("pv.codigo_externo || ' × ' || v.codigo_externo"), $chaves)
                    ->selectRaw("pv.codigo_externo || ' × ' || v.codigo_externo as chave")
                    ->pluck('chave')
                    ->mapWithKeys(fn ($c) => [$c => true])->all();
            },
            fn ($linhas, $simular) => CarteiraVendedores::executar($empresa, $linhas, tudoOuNada: false, simular: $simular),
        );
    }

    /** Promotores com senha inicial (só na criação; a senha nunca é trocada por aqui). */
    public function promotores(Request $request): JsonResponse
    {
        $empresa = $this->empresa($request);

        return $this->lote(
            $request, $this->itens($request), PromotoresErp::COLUNAS,
            fn ($d) => $d['codigo_externo'] ?? null,
            fn ($codigos) => PromotoresErp::existentes($empresa, $codigos),
            fn ($linhas, $simular) => PromotoresErp::aplicar($empresa, $linhas, $simular),
        );
    }

    /** Pedidos do ERP com itens, valor e faturamento (até 100 por chamada, cada um com até 500 itens). */
    public function pedidos(Request $request): JsonResponse
    {
        $empresa = $this->empresa($request);

        return $this->lote(
            $request, $this->itens($request, PedidosErp::MAX_PEDIDOS), null,
            fn ($d) => is_scalar($d['numero_pedido'] ?? null) ? (string) $d['numero_pedido'] : null,
            fn ($numeros) => PedidosErp::pedidosExistentes($empresa, $numeros),
            fn ($linhas, $simular) => PedidosErp::aplicarPedidos($empresa, $linhas, $simular),
        );
    }

    /** Entregas dos pedidos (idempotente por pedido + horário). */
    public function entregas(Request $request): JsonResponse
    {
        $empresa = $this->empresa($request);

        return $this->lote(
            $request, $this->itens($request, PedidosErp::MAX_ENTREGAS), ['numero_pedido', 'data_entrega', 'observacao'],
            fn ($d) => isset($d['numero_pedido']) ? $d['numero_pedido'].(isset($d['data_entrega']) ? '@'.$d['data_entrega'] : '') : null,
            fn ($chaves) => PedidosErp::entregasExistentes($empresa, $chaves),
            fn ($linhas, $simular) => PedidosErp::aplicarEntregas($empresa, $linhas, $simular),
        );
    }

    private function apoio(Request $request, string $modelo, array $colunas, string $metodo): JsonResponse
    {
        $empresa = $this->empresa($request);

        return $this->lote(
            $request, $this->itens($request), $colunas,
            fn ($d) => $d['descricao'] ?? null,
            fn ($nomes) => CadastroApoioErp::existentes($modelo, $empresa, $nomes),
            fn ($linhas, $simular) => [CadastroApoioErp::class, $metodo]($empresa, $linhas, $simular),
        );
    }

    private function lote(Request $request, array $itens, ?array $colunas, callable $chave, callable $existentes, callable $aplicar): JsonResponse
    {
        $resultado = LoteIntegracao::executar($itens, $colunas, $chave, $existentes, $aplicar, $request->boolean('simular'));

        if (isset($resultado['erro_geral'])) {
            return ProblemaIntegracao::resposta(422, $resultado['erro_geral']);
        }

        $request->attributes->set(EnsureIntegracao::ATRIBUTO_REGISTROS, $resultado['criados'] + $resultado['atualizados']);

        return response()->json($resultado);
    }

    private function itens(Request $request, int $maximo = LoteIntegracao::MAX_ITENS): array
    {
        $request->validate([
            'itens' => ['required', 'array', 'min:1', 'max:'.$maximo],
        ], [
            'itens.required' => 'Envie os registros em "itens": [...].',
            'itens.max' => 'Máximo de :max itens por chamada: divida em partes.',
        ]);

        return array_values($request->input('itens'));
    }

    private function empresa(Request $request): Empresa
    {
        return $request->user()->empresa;
    }

    /** @return array<string, true> */
    private function porCodigo(string $modelo, Empresa $empresa, array $codigos): array
    {
        return $modelo::withoutGlobalScopes()->where('empresa_id', $empresa->id)->whereIn('codigo_externo', $codigos)
            ->pluck('codigo_externo')->mapWithKeys(fn ($c) => [$c => true])->all();
    }
}

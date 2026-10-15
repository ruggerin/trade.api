<?php

namespace App\Http\Controllers;

use App\Support\CarteiraVendedores;
use App\Support\EquipeComercial;
use App\Support\ImportacaoProdutos;
use App\Support\ImportacaoRotaVisitas;
use App\Support\ImportacaoSortimento;
use App\Support\LeitorCsv;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Importação de Dados (docs/42-IMPORTACAO-DE-DADOS.md) — Produto e Vínculo Loja × Produto. A de
 * Loja continua em PontoVendaController::importar (docs/41). Mesmo contrato nas três: multipart
 * `arquivo` + `simular=1|0`, relatório com linha/código/mensagem por erro, tudo ou nada.
 */
class ImportacaoDadosController extends Controller
{
    public function produtos(Request $request): JsonResponse
    {
        $this->validarArquivo($request, 5);

        return response()->json(ImportacaoProdutos::executar(
            $request->user()->empresa,
            $request->file('arquivo')->get(),
            $request->boolean('simular'),
        ));
    }

    public function sortimento(Request $request): JsonResponse
    {
        // Arquivo maior que os outros: todo PDV × cada produto do mix (até 50.000 linhas).
        $this->validarArquivo($request, 10);

        return response()->json(ImportacaoSortimento::executar(
            $request->user()->empresa,
            $request->file('arquivo')->get(),
            $request->boolean('simular'),
        ));
    }

    /** Equipe comercial do ERP (docs/73): planilha tudo ou nada, com pré-visualização (`simular`). */
    public function supervisores(Request $request): JsonResponse
    {
        return $this->equipe($request, EquipeComercial::COLUNAS_SUPERVISOR, 'supervisor', EquipeComercial::supervisores(...));
    }

    public function vendedores(Request $request): JsonResponse
    {
        return $this->equipe($request, EquipeComercial::COLUNAS_VENDEDOR, 'vendedor', EquipeComercial::vendedores(...));
    }

    /**
     * Rota de visitas (docs/78 §4): dias de atendimento por loja + promotor viram o planejamento
     * semanal. `promotor_padrao` vale pras linhas sem a coluna `promotor`.
     */
    public function rotaVisitas(Request $request): JsonResponse
    {
        $this->validarArquivo($request, 5);
        $request->validate(['promotor_padrao' => ['nullable', 'string', 'max:255']]);

        return response()->json(ImportacaoRotaVisitas::executar(
            $request->user()->empresa,
            $request->file('arquivo')->get(),
            $request->boolean('simular'),
            $request->input('promotor_padrao'),
        ));
    }

    /** Carteira vendedor × loja (docs/73 §11.1) — substitui a carteira de cada loja do arquivo. */
    public function carteira(Request $request): JsonResponse
    {
        $this->validarArquivo($request, 10);

        $linhas = LeitorCsv::ler($request->file('arquivo')->get(), CarteiraVendedores::COLUNAS, CarteiraVendedores::COLUNAS, CarteiraVendedores::MAX_LINHAS, 'vínculo');
        if (isset($linhas['erro'])) {
            return response()->json([
                'total' => 0, 'lojas' => 0, 'criadas' => 0, 'removidas' => 0, 'aplicado' => false,
                'erros' => [['linha' => 1, 'codigo_externo' => null, 'mensagem' => $linhas['erro']]],
            ]);
        }

        return response()->json(CarteiraVendedores::executar($request->user()->empresa, $linhas, true, $request->boolean('simular')));
    }

    private function equipe(Request $request, array $colunas, string $nomeItem, callable $carga): JsonResponse
    {
        $this->validarArquivo($request, 5);

        $linhas = LeitorCsv::ler($request->file('arquivo')->get(), $colunas, ['codigo_externo'], EquipeComercial::MAX_LINHAS, $nomeItem);
        if (isset($linhas['erro'])) {
            return response()->json([
                'total' => 0, 'criadas' => 0, 'atualizadas' => 0, 'avisos' => [], 'aplicado' => false,
                'erros' => [['linha' => 1, 'codigo_externo' => null, 'mensagem' => $linhas['erro']]],
            ]);
        }

        return response()->json($carga($request->user()->empresa, $linhas, true, $request->boolean('simular')));
    }

    private function validarArquivo(Request $request, int $megas): void
    {
        $request->validate([
            'arquivo' => ['required', 'file', 'max:'.($megas * 1024), 'mimes:csv,txt'],
            'simular' => ['nullable', 'boolean'],
        ], [
            'arquivo.mimes' => 'Envie um arquivo .csv (no Excel: Salvar como → CSV).',
            'arquivo.max' => "Arquivo grande demais (máximo {$megas} MB) — divida em partes.",
        ]);
    }
}

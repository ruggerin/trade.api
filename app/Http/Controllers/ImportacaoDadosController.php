<?php

namespace App\Http\Controllers;

use App\Support\ImportacaoProdutos;
use App\Support\ImportacaoSortimento;
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

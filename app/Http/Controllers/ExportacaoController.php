<?php

namespace App\Http\Controllers;

use App\Enums\UserType;
use App\Models\Exportacao;
use App\Support\Exportacao\Catalogo;
use App\Support\Exportacao\Exportador;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Exportação padronizada (docs/68). O arquivo sai da MESMA consulta da tela, no servidor — o
 * navegador só pede "esta rotina, estes filtros, este formato".
 */
class ExportacaoController extends Controller
{
    public function store(Request $request, Exportador $exportador): BinaryFileResponse
    {
        $usuario = $request->user();

        // PROMOTOR nunca exporta pelo admin (docs/68 §4.4); SUPERADMIN não tem empresa pra exportar.
        if (! in_array($usuario->user_type, [UserType::ADMIN, UserType::GESTOR], true)) {
            abort(403, 'A exportação é só para ADMIN/GESTOR.');
        }

        $dados = $request->validate([
            'rotina' => ['required', 'string', Rule::in(Catalogo::chaves())],
            'formato' => ['required', Rule::in(Exportador::FORMATOS)],
            'filtros' => ['nullable', 'array'],
            // Texto humano dos filtros ("Loja: Mercado Central · Status: Aberto") pra aba "Sobre".
            'descricao_filtros' => ['nullable', 'string', 'max:500'],
        ]);

        $rotina = Catalogo::achar($dados['rotina']);

        if (! $usuario->temPermissao($rotina->permissao())) {
            abort(403, 'Você não tem acesso à tela desta exportação.');
        }

        // Só os filtros que a tela da rotina realmente tem.
        $filtros = array_intersect_key($dados['filtros'] ?? [], array_flip($rotina->filtrosPermitidos()));
        $filtros = array_filter($filtros, fn ($v) => $v !== null && $v !== '');

        $arquivo = $exportador->gerar($rotina, $dados['formato'], $filtros, $usuario, $dados['descricao_filtros'] ?? null);

        Exportacao::create([
            'empresa_id' => $usuario->empresa_id,
            'usuario_id' => $usuario->id,
            'rotina' => $rotina::chave(),
            'formato' => $dados['formato'],
            'filtros' => $filtros,
            'linhas' => $arquivo['linhas'],
        ]);

        return response()
            ->download($arquivo['caminho'], $arquivo['nome'], ['Content-Type' => $arquivo['mime'], 'X-Linhas-Exportadas' => (string) $arquivo['linhas']])
            ->deleteFileAfterSend(true);
    }
}

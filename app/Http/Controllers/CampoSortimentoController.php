<?php

namespace App\Http\Controllers;

use App\Enums\TipoCampoRegistro;
use App\Models\CampoTipoRegistro;
use App\Models\PontoVenda;
use App\Models\TipoRegistro;
use App\Support\ResolverSortimentoCampo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Resolve o checklist de produtos de um campo SORTIMENTO pra um PDV — consultado pelo mobile ao
 * abrir o formulário (RegistroFormModal), antes de renderizar a lista pré-marcada como presente.
 * Ver App\Support\ResolverSortimentoCampo e docs/20-FORMULARIO-DINAMICO-CAMPANHA.md decisão 3.
 */
class CampoSortimentoController extends Controller
{
    public function index(Request $request, CampoTipoRegistro $campo): JsonResponse
    {
        $request->validate(['ponto_venda_uuid' => ['required', 'string']]);

        // CampoTipoRegistro não tem BelongsToEmpresa própria (herda de tipoRegistro) — confirma
        // manualmente que o campo pertence à empresa de quem está chamando, mesmo raciocínio de
        // SortimentoPontoVendaController::destroy pra ponto_venda. `withoutGlobalScopes()`: o
        // scope de BelongsToEmpresa em TipoRegistro filtra pelo empresa_id do usuário LOGADO —
        // pra um campo de outra empresa, `$campo->tipoRegistro` viria `null` (escondido pelo
        // scope) em vez do registro de fato, quebrando a comparação abaixo com null->empresa_id.
        $empresaIdDoCampo = TipoRegistro::withoutGlobalScopes()->where('id', $campo->tipo_registro_id)->value('empresa_id');
        abort_if($empresaIdDoCampo !== $request->user()->empresa_id, 404);
        abort_if($campo->tipo_campo !== TipoCampoRegistro::SORTIMENTO, 422, 'Este campo não é do tipo SORTIMENTO.');

        $pontoVenda = PontoVenda::where('uuid', $request->string('ponto_venda_uuid'))->firstOrFail();

        $produtos = ResolverSortimentoCampo::resolver($campo, $pontoVenda);

        return response()->json([
            'produtos' => $produtos->map(fn ($produto) => [
                'produto_uuid' => $produto->uuid,
                'descricao' => $produto->descricao,
                'imagem_url' => $produto->imagem_url,
                'codigo_barras' => $produto->codigo_barras,
                'propriedade' => $produto->propriedade,
                'produto_chave' => $produto->produto_chave,
            ])->values(),
        ]);
    }
}

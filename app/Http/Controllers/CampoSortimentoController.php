<?php

namespace App\Http\Controllers;

use App\Enums\TipoCampoRegistro;
use App\Models\CampoTipoRegistro;
use App\Models\PontoVenda;
use App\Models\ProdutoAuditoria;
use App\Models\TipoRegistro;
use App\Support\ResolverSortimentoCampo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

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

        $resposta = [
            'produtos' => $produtos->map(fn ($produto) => [
                'produto_uuid' => $produto->uuid,
                'descricao' => $produto->descricao,
                'imagem_url' => $produto->imagem_url,
                'codigo_barras' => $produto->codigo_barras,
                'propriedade' => $produto->propriedade,
                'produto_chave' => $produto->produto_chave,
                // Pro app filtrar e agrupar pelo recorte escolhido (docs/79 §4.2).
                'secao_uuid' => $produto->secao?->uuid,
                'secao_descricao' => $produto->secao?->descricao,
                'departamento_uuid' => $produto->departamento?->uuid,
                'departamento_descricao' => $produto->departamento?->descricao,
                // Marca do próprio produto (docs/82 §5.3): checklist por marca e filtro de marca.
                'marca_uuid' => $produto->marca?->uuid,
                'marca_descricao' => $produto->marca?->descricao,
            ])->values(),
        ];

        // Campo que segue a escolha do promotor: o que dá pra escolher na tela "Onde é o ponto
        // extra?" — só o que existe no mix desta loja.
        if ($campo->sortimento_segue_escolha) {
            $resposta['recortes'] = $this->recortesDoMix($produtos);
        }

        return response()->json($resposta);
    }

    /** @param Collection<int, ProdutoAuditoria> $produtos */
    private function recortesDoMix(Collection $produtos): array
    {
        $departamentos = $produtos->pluck('departamento')->filter()->unique('id')->sortBy('descricao')->values();

        // Marcas pelos PRODUTOS do mix (docs/82 §5.3), cada uma com onde aparece nesta loja — o
        // filtro de marca depois da seção mostra só as marcas daquela seção (§5.2).
        $marcas = $produtos->filter(fn ($p) => $p->marca)
            ->groupBy('marca_id')
            ->map(fn (Collection $daMarca) => [
                'uuid' => $daMarca->first()->marca->uuid,
                'descricao' => $daMarca->first()->marca->descricao,
                'departamentos_uuids' => $daMarca->pluck('departamento.uuid')->filter()->unique()->values(),
                'secoes_uuids' => $daMarca->pluck('secao.uuid')->filter()->unique()->values(),
            ])
            ->sortBy('descricao')
            ->values();

        return [
            'marcas' => $marcas,
            'departamentos' => $departamentos->map(fn ($d) => ['uuid' => $d->uuid, 'descricao' => $d->descricao]),
            'secoes' => $produtos->pluck('secao')->filter()->unique('id')->sortBy('descricao')->values()->map(fn ($s) => [
                'uuid' => $s->uuid,
                'descricao' => $s->descricao,
                'departamento_uuid' => $departamentos->firstWhere('id', $s->departamento_id)?->uuid,
            ]),
        ];
    }
}

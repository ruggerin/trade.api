<?php

namespace App\Support;

use App\Enums\SortimentoOrigemCampo;
use App\Enums\TipoItemCampanha;
use App\Models\CampoTipoRegistro;
use App\Models\PontoVenda;
use App\Models\ProdutoAuditoria;
use App\Models\SortimentoPontoVenda;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Resolve a lista de produtos de um campo SORTIMENTO pra um PDV — ver
 * docs/20-FORMULARIO-DINAMICO-CAMPANHA.md decisão 3 e docs/79-CHECKLIST-MIX-POR-ESCOLHA-DO-
 * PROMOTOR.md. Usado tanto pelo endpoint que o mobile consulta pra montar o checklist
 * (CampoSortimentoController) quanto pela validação de `StoreVisitaRegistroRequest` (cada uuid
 * respondido precisa estar neste conjunto).
 */
class ResolverSortimentoCampo
{
    /**
     * `$tipoRecorte`/`$recorteId` = o recorte escolhido pelo promotor no registro — só usado
     * quando o campo tem `sortimento_segue_escolha`. Sem ele, um campo que segue a escolha
     * devolve o mix inteiro do PDV (o app filtra localmente, docs/79 §4.2).
     *
     * @return Collection<int, ProdutoAuditoria>
     */
    public static function resolver(
        CampoTipoRegistro $campo,
        PontoVenda $pontoVenda,
        ?TipoItemCampanha $tipoRecorte = null,
        ?int $recorteId = null,
        ?int $marcaId = null,
    ): Collection {
        return $campo->sortimento_origem === SortimentoOrigemCampo::FIXO
            ? self::resolverFixo($campo)
            : self::resolverDinamico($campo, $pontoVenda, $tipoRecorte, $recorteId, $marcaId);
    }

    /**
     * Produtos ativos do catálogo dentro de um recorte, estejam ou não no mix de alguma loja.
     * Marca = a marca do PRÓPRIO produto (docs/82 §5.3). Antes era "os departamentos ligados à
     * marca" (MarcaDepartamento), que num departamento compartilhado trazia as outras marcas
     * junto. A campanha por marca (CampanhaAuditoriaController) segue no critério antigo.
     * `$marcaId` = filtro de marca depois da seção/departamento (docs/82 §5.2): interseção.
     *
     * @return Builder<ProdutoAuditoria>
     */
    public static function catalogoDoRecorte(TipoItemCampanha $tipo, int $id, ?int $marcaId = null): Builder
    {
        $query = ProdutoAuditoria::query()->where('ativo', true);

        $query = match ($tipo) {
            TipoItemCampanha::PRODUTO => $query->where('id', $id),
            TipoItemCampanha::SECAO => $query->where('secao_id', $id),
            TipoItemCampanha::DEPARTAMENTO => $query->where('departamento_id', $id),
            TipoItemCampanha::MARCA => $query->where('marca_id', $id),
        };

        return $marcaId !== null ? $query->where('marca_id', $marcaId) : $query;
    }

    /** Lista curada no cadastro do campo — ignora o sortimento real do PDV. */
    private static function resolverFixo(CampoTipoRegistro $campo): Collection
    {
        return $campo->produtosFixos()->with('secao')->where('ativo', true)->get();
    }

    /**
     * Dentro do recorte (fixo no campo ou escolhido pelo promotor), os produtos que o PDV tem no
     * próprio sortimento. Item de sortimento cadastrado como seção/departamento/marca inteira
     * vale por todos os produtos ativos daquele recorte (docs/79 decisão 7) — antes só entravam
     * os produtos cadastrados um por um. `status_aprovacao` pendente fica de fora — mesmo
     * raciocínio de CampanhaAuditoriaController::disponiveis (não mostra o que ainda não foi
     * aprovado).
     */
    private static function resolverDinamico(
        CampoTipoRegistro $campo,
        PontoVenda $pontoVenda,
        ?TipoItemCampanha $tipoRecorte,
        ?int $recorteId,
        ?int $marcaId = null,
    ): Collection {
        [$tipo, $id] = $campo->sortimento_segue_escolha
            ? [$tipoRecorte, $recorteId]
            : [$campo->sortimento_tipo_vinculo, match ($campo->sortimento_tipo_vinculo) {
                TipoItemCampanha::SECAO => $campo->sortimento_secao_id,
                TipoItemCampanha::DEPARTAMENTO => $campo->sortimento_departamento_id,
                TipoItemCampanha::MARCA => $campo->sortimento_marca_id,
                default => null,
            }];

        // Campo com recorte fixo mal configurado: nada a listar, como antes.
        if (! $campo->sortimento_segue_escolha && ($tipo === null || $id === null)) {
            return collect();
        }

        $itens = SortimentoPontoVenda::where('ponto_venda_id', $pontoVenda->id)
            ->whereNull('status_aprovacao')
            ->get(['tipo_item', 'produto_id', 'secao_id', 'departamento_id', 'marca_id']);

        if ($itens->isEmpty()) {
            return collect();
        }

        $porTipo = fn (TipoItemCampanha $t, string $coluna) => $itens->where('tipo_item', $t)->pluck($coluna)->filter()->unique()->values();
        $produtoIds = $porTipo(TipoItemCampanha::PRODUTO, 'produto_id');
        $secaoIds = $porTipo(TipoItemCampanha::SECAO, 'secao_id');
        $departamentoIds = $porTipo(TipoItemCampanha::DEPARTAMENTO, 'departamento_id');
        // Item "marca inteira" = todos os produtos daquela marca (docs/82 §5.3).
        $marcaIds = $porTipo(TipoItemCampanha::MARCA, 'marca_id');

        $query = ($tipo !== null && $id !== null)
            ? self::catalogoDoRecorte($tipo, $id, $campo->sortimento_segue_escolha ? $marcaId : null)
            : ProdutoAuditoria::query()->where('ativo', true);

        return $query
            ->where(fn (Builder $q) => $q
                ->whereIn('id', $produtoIds)
                ->orWhereIn('secao_id', $secaoIds)
                ->orWhereIn('departamento_id', $departamentoIds)
                ->orWhereIn('marca_id', $marcaIds))
            ->with(['secao', 'departamento', 'marca'])
            ->orderBy('descricao')
            ->get();
    }
}

<?php

namespace App\Http\Controllers;

use App\Enums\EscopoAcaoTipoRegistro;
use App\Http\Requests\TipoRegistro\MoverTipoRegistroRequest;
use App\Http\Requests\TipoRegistro\StoreTipoRegistroRequest;
use App\Http\Requests\TipoRegistro\UpdateTipoRegistroRequest;
use App\Http\Resources\TipoRegistroResource;
use App\Models\CampanhaAuditoria;
use App\Models\CampoTipoRegistro;
use App\Models\DepartamentoAuditoria;
use App\Models\Empresa;
use App\Models\MarcaAuditoria;
use App\Models\PontoVenda;
use App\Models\ProdutoAuditoria;
use App\Models\RamoAtividade;
use App\Models\RedeLoja;
use App\Models\SecaoAuditoria;
use App\Models\TipoRegistro;
use App\Models\TipoRegistroSecaoExcecao;
use App\Models\VisitaRegistro;
use App\Support\LinhaAusente;
use App\Support\ConcorrentesPorMarca;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class TipoRegistroController extends Controller
{
    // Relações do campo carregadas em toda resposta com TipoRegistroResource — inclui o
    // condicional (decisão 7) e o sortimento (decisão 3), ambos de
    // docs/20-FORMULARIO-DINAMICO-CAMPANHA.md.
    private const RELACOES_CAMPOS = [
        'campos.dependeDe', 'campos.sortimentoSecao', 'campos.sortimentoDepartamento',
        'campos.sortimentoMarca', 'campos.produtosFixos',
    ];

    // Escopo LOJA_REDE (docs/40) — o mobile resolve a Ação com esses uuids × a rede do PDV.
    private const RELACOES_ESCOPO = [
        'pontosVenda:id,uuid,fantasia', 'redesLojas:id,uuid,descricao',
        // LINHA_AUSENTE e filtro por ramo (docs/80) — o mobile resolve a Ação com o Mix em cache.
        'escopoSecao:id,uuid,descricao,departamento_id', 'escopoSecao.departamento:id,uuid',
        'escopoDepartamento:id,uuid,descricao', 'escopoMarca:id,uuid,descricao',
        'ramosAtividade:id,uuid,descricao',
        // Lista predefinida de produtos (granularidade PRODUTO) — o mobile usa como opções de vínculo.
        'produtosPredefinidos:id,uuid,descricao,codigo_barras,codigo_externo',
        // Lista de marcas concorrentes (granularidade MARCA, docs/85) — a lista do app.
        'marcasPredefinidas:id,uuid,descricao,status_aprovacao',
    ];

    public function index(Request $request): JsonResponse
    {
        $tipos = TipoRegistro::query()
            ->when($request->has('ativo'), fn ($query) => $query->where('ativo', $request->boolean('ativo')))
            // Registro espontâneo: o promotor pode abrir esse formulário por conta própria na visita,
            // sem estar pedido numa OS/Direcionamento.
            ->when($request->has('disponivel_registro_livre'), fn ($query) => $query->where('disponivel_registro_livre', $request->boolean('disponivel_registro_livre')))
            ->when($request->filled('busca'), function ($query) use ($request) {
                $termo = '%'.addcslashes($request->string('busca'), '%_\\').'%';
                $query->where('descricao', 'ilike', $termo);
            })
            // Só tem efeito prático pro SUPERADMIN — ver DepartamentoAuditoriaController::index.
            ->when(
                $request->filled('empresa_uuid'),
                fn ($query) => $query->where('empresa_id', Empresa::where('uuid', $request->string('empresa_uuid'))->value('id')),
            )
            // "Formulário desta campanha" (Fase 3, autoria embutida) — a tela de Campanha lista
            // só os tipos vinculados a ela, ver docs/20-FORMULARIO-DINAMICO-CAMPANHA.md §3.
            ->when(
                $request->filled('campanha_auditoria_uuid'),
                fn ($query) => $query->whereHas(
                    'campanhaAuditoria',
                    fn ($q) => $q->where('uuid', $request->string('campanha_auditoria_uuid')),
                ),
            )
            ->with([...self::RELACOES_CAMPOS, 'empresa', 'campanhaAuditoria', 'excecoesGranularidade.secao', ...self::RELACOES_ESCOPO])
            // Sequência de exibição escolhida pelo gestor (ver mover() abaixo) — mesma ordem
            // pro admin e pro mobile, que consome este mesmo endpoint. `descricao` só entra
            // como desempate defensivo (na operação normal `ordem` já é único por empresa).
            ->orderBy('ordem')
            ->orderBy('descricao')
            // `por_pagina` opt-in (até 200) — "copiar de outro formulário" precisa da lista toda.
            ->paginate($request->filled('por_pagina') ? min(max($request->integer('por_pagina'), 1), 200) : null);

        return response()->json([
            'tipos_registro' => TipoRegistroResource::collection($tipos->items()),
            'meta' => [
                'current_page' => $tipos->currentPage(),
                'last_page' => $tipos->lastPage(),
                'per_page' => $tipos->perPage(),
                'total' => $tipos->total(),
            ],
        ]);
    }

    /**
     * Busca individual — usada pela página dedicada de edição no admin (não é mais modal, ver
     * docs/25-DIRECIONAMENTO-ORDEM-SERVICO.md e a TipoRegistroFormPage). Leitura aberta, mesmo
     * padrão de index().
     */
    public function show(TipoRegistro $tipoRegistro): JsonResponse
    {
        $tipoRegistro->load([...self::RELACOES_CAMPOS, 'campanhaAuditoria', 'excecoesGranularidade.secao', ...self::RELACOES_ESCOPO]);

        return response()->json([
            'tipo_registro' => new TipoRegistroResource($tipoRegistro),
        ]);
    }

    public function store(StoreTipoRegistroRequest $request): JsonResponse
    {
        $dados = $request->validated();

        // Transação: `sincronizarCampos`/`sincronizarExcecoesGranularidade` são várias queries
        // depois do `TipoRegistro` já criado — sem isso, uma falha no meio (ex.: um valor de
        // campo rejeitado pelo banco que a validação não pegou) deixa um `TipoRegistro` órfão,
        // sem nenhum campo, em vez de nada ser salvo.
        $tipo = DB::transaction(function () use ($dados) {
            $escolheRecorte = ($dados['escolhe_marca'] ?? false) || ($dados['escolhe_departamento'] ?? false) || ($dados['escolhe_secao'] ?? false);
            $tipo = TipoRegistro::create([
                'descricao' => $dados['descricao'],
                'icone' => $dados['icone'] ?? null,
                // Novo tipo sempre vai pro fim da lista — mesmo raciocínio de "onde entraria um
                // item novo" em qualquer lista ordenada manualmente. Scoped à empresa pelo global
                // scope de BelongsToEmpresa (mesma query que index() usa).
                'ordem' => (TipoRegistro::max('ordem') ?? -1) + 1,
                'exige_foto' => $dados['exige_foto'] ?? false,
                'permite_vincular_catalogo' => $dados['permite_vincular_catalogo'] ?? false,
                'acao_obrigatoria' => $dados['acao_obrigatoria'] ?? false,
                'escopo_acao' => $dados['escopo_acao'] ?? null,
                'campanha_auditoria_id' => $this->resolverCampanhaAuditoriaId($dados['campanha_auditoria_uuid'] ?? null),
                // Com escolha de recorte a resposta cobre a gôndola, nunca um produto (docs/79 §4.1).
                'granularidade_padrao' => $escolheRecorte ? 'LINHA' : ($dados['granularidade_padrao'] ?? null),
                'escolhe_marca' => $dados['escolhe_marca'] ?? false,
                'escolhe_departamento' => $dados['escolhe_departamento'] ?? false,
                'escolhe_secao' => $dados['escolhe_secao'] ?? false,
                'lista_recorte' => $dados['lista_recorte'] ?? TipoRegistro::LISTA_PRIMEIRO_DA_LOJA,
                'filtra_marca' => $dados['filtra_marca'] ?? false,
                'permite_marca_nova' => $dados['permite_marca_nova'] ?? true,
                'lista_produtos_origem' => $dados['lista_produtos_origem'] ?? TipoRegistro::LISTA_PRODUTOS_FIXA,
                'varias_embalagens' => $dados['varias_embalagens'] ?? true,
                ...self::camposAcompanhamento($dados),
                'intervalo_dias' => $dados['intervalo_dias'] ?? 0,
                'eh_ruptura' => $dados['eh_ruptura'] ?? false,
                'eh_alerta' => $dados['eh_alerta'] ?? false,
                'usa_pontuacao' => $dados['usa_pontuacao'] ?? false,
                // Régua de XP (docs/88 §9.1) e avaliação do gestor (doc 33).
                'xp_iniciativa' => $dados['xp_iniciativa'] ?? 0,
                'xp_bonus_acao' => $dados['xp_bonus_acao'] ?? 0,
                'xp_teto_por_visita' => $dados['xp_teto_por_visita'] ?? null,
                'xp_intervalo_dias' => $dados['xp_intervalo_dias'] ?? 0,
                'exige_avaliacao' => $dados['exige_avaliacao'] ?? false,
                'disponivel_registro_livre' => $dados['disponivel_registro_livre'] ?? true,
            ]);
            $this->sincronizarCampos($tipo, $dados['campos'] ?? []);
            $this->sincronizarExcecoesGranularidade($tipo, $dados['excecoes_granularidade'] ?? []);
            $this->sincronizarEscopoLojaRede($tipo, $dados);
            $this->sincronizarEscopoLinhaAusente($tipo, $dados);
            $this->sincronizarRamosAtividade($tipo, $dados);
            $this->sincronizarProdutosPredefinidos($tipo, $dados);
            $this->sincronizarMarcasPredefinidas($tipo, $dados);

            return $tipo;
        });
        $tipo->load([...self::RELACOES_CAMPOS, 'campanhaAuditoria', 'excecoesGranularidade.secao', ...self::RELACOES_ESCOPO]);

        return response()->json([
            'tipo_registro' => new TipoRegistroResource($tipo),
        ], 201);
    }

    public function update(UpdateTipoRegistroRequest $request, TipoRegistro $tipoRegistro): JsonResponse
    {
        $dados = $request->validated();

        if (array_key_exists('campanha_auditoria_uuid', $dados)) {
            $dados['campanha_auditoria_id'] = $this->resolverCampanhaAuditoriaId($dados['campanha_auditoria_uuid']);
            unset($dados['campanha_auditoria_uuid']);
        }
        if (array_key_exists('acompanha_formulario_acabou_uuid', $dados)) {
            $dados['acompanha_formulario_acabou_id'] = $dados['acompanha_formulario_acabou_uuid']
                ? TipoRegistro::where('uuid', $dados['acompanha_formulario_acabou_uuid'])->value('id')
                : null;
            unset($dados['acompanha_formulario_acabou_uuid']);
        }
        // Escopo deixou de ser CAMPANHA — não faz sentido manter a campanha antiga amarrada.
        if (($dados['escopo_acao'] ?? null) !== 'CAMPANHA' && array_key_exists('escopo_acao', $dados)) {
            $dados['campanha_auditoria_id'] = null;
        }

        // Mesma razão da transação em store(): sincronizarCampos/sincronizarExcecoesGranularidade
        // fazem delete-all + recreate — uma falha no meio deixaria o tipo sem NENHUM campo em vez
        // de manter o estado anterior ou salvar o novo por completo.
        DB::transaction(function () use ($tipoRegistro, $dados): void {
            $tipoRegistro->update(collect($dados)->except([
                'campos', 'excecoes_granularidade', 'pontos_venda_uuids', 'redes_lojas_uuids', 'produtos_uuids', 'marcas_uuids',
                'escopo_tipo_vinculo', 'escopo_secao_uuid', 'escopo_departamento_uuid', 'escopo_marca_uuid', 'ramos_atividade_uuids',
            ])->all());
            // Mesma regra de store(): com escolha de recorte a granularidade é sempre LINHA.
            if ($tipoRegistro->temEscolhaDeRecorte() && $tipoRegistro->granularidade_padrao?->value !== 'LINHA') {
                $tipoRegistro->update(['granularidade_padrao' => 'LINHA']);
            }

            // Só mexe nas pivôs quando o escopo ou as listas vieram no request — um update parcial
            // (ex.: só `ativo`) não pode apagar a configuração de lojas/redes.
            if (array_key_exists('escopo_acao', $dados) || array_key_exists('acao_obrigatoria', $dados)
                || array_key_exists('pontos_venda_uuids', $dados) || array_key_exists('redes_lojas_uuids', $dados)) {
                $this->sincronizarEscopoLojaRede($tipoRegistro->refresh(), $dados);
            }
            if (array_key_exists('escopo_acao', $dados) || array_key_exists('acao_obrigatoria', $dados)
                || array_key_exists('escopo_tipo_vinculo', $dados)) {
                $this->sincronizarEscopoLinhaAusente($tipoRegistro->refresh(), $dados);
            }
            if (array_key_exists('ramos_atividade_uuids', $dados) || array_key_exists('acao_obrigatoria', $dados)) {
                $this->sincronizarRamosAtividade($tipoRegistro->refresh(), $dados);
            }
            // Mesmo cuidado: update parcial (só descrição/ativo) não apaga a lista de produtos.
            if (array_key_exists('produtos_uuids', $dados) || array_key_exists('granularidade_padrao', $dados) || array_key_exists('lista_produtos_origem', $dados)) {
                $this->sincronizarProdutosPredefinidos($tipoRegistro->refresh(), $dados);
            }
            if (array_key_exists('marcas_uuids', $dados) || array_key_exists('granularidade_padrao', $dados)) {
                $this->sincronizarMarcasPredefinidas($tipoRegistro->refresh(), $dados);
            }

            if (array_key_exists('campos', $dados)) {
                $this->sincronizarCampos($tipoRegistro, $dados['campos']);
            }

            if (array_key_exists('excecoes_granularidade', $dados)) {
                $this->sincronizarExcecoesGranularidade($tipoRegistro, $dados['excecoes_granularidade']);
            }
        });

        $tipoRegistro->load([...self::RELACOES_CAMPOS, 'campanhaAuditoria', 'excecoesGranularidade.secao', ...self::RELACOES_ESCOPO]);

        return response()->json([
            'tipo_registro' => new TipoRegistroResource($tipoRegistro),
        ]);
    }

    /**
     * Pivôs do escopo LOJA_REDE (docs/40 §3.2). Qualquer outro escopo (ou Ação não obrigatória)
     * limpa as duas — mesmo raciocínio de `campanha_auditoria_id` ao sair de CAMPANHA.
     */
    private function sincronizarEscopoLojaRede(TipoRegistro $tipo, array $dados): void
    {
        if (! $tipo->acao_obrigatoria || $tipo->escopo_acao !== EscopoAcaoTipoRegistro::LOJA_REDE) {
            $tipo->pontosVenda()->detach();
            $tipo->redesLojas()->detach();

            return;
        }

        if (array_key_exists('pontos_venda_uuids', $dados)) {
            $tipo->pontosVenda()->sync(PontoVenda::whereIn('uuid', $dados['pontos_venda_uuids'] ?? [])->pluck('id'));
        }
        if (array_key_exists('redes_lojas_uuids', $dados)) {
            $tipo->redesLojas()->sync(RedeLoja::whereIn('uuid', $dados['redes_lojas_uuids'] ?? [])->pluck('id'));
        }
    }

    /**
     * Recorte do escopo LINHA_AUSENTE (docs/80 §5.1). Qualquer outro escopo (ou Ação não
     * obrigatória) zera as quatro colunas — mesmo raciocínio das pivôs de LOJA_REDE.
     */
    private function sincronizarEscopoLinhaAusente(TipoRegistro $tipo, array $dados): void
    {
        $tipoVinculo = $dados['escopo_tipo_vinculo'] ?? null;
        $vale = $tipo->acao_obrigatoria && $tipo->escopo_acao === EscopoAcaoTipoRegistro::LINHA_AUSENTE;

        if ($vale && $tipoVinculo === null) {
            return; // update que não mexeu no recorte: mantém o que está salvo
        }

        $tipo->update([
            'escopo_tipo_vinculo' => $vale ? $tipoVinculo : null,
            'escopo_secao_id' => $vale && $tipoVinculo === 'SECAO'
                ? SecaoAuditoria::where('uuid', $dados['escopo_secao_uuid'])->value('id') : null,
            'escopo_departamento_id' => $vale && $tipoVinculo === 'DEPARTAMENTO'
                ? DepartamentoAuditoria::where('uuid', $dados['escopo_departamento_uuid'])->value('id') : null,
            'escopo_marca_id' => $vale && $tipoVinculo === 'MARCA'
                ? MarcaAuditoria::where('uuid', $dados['escopo_marca_uuid'])->value('id') : null,
        ]);
    }

    /** Filtro por ramo de atividade (docs/80 §5.4) — só vale em Ação obrigatória; senão limpa. */
    private function sincronizarRamosAtividade(TipoRegistro $tipo, array $dados): void
    {
        if (! $tipo->acao_obrigatoria) {
            $tipo->ramosAtividade()->detach();

            return;
        }

        if (array_key_exists('ramos_atividade_uuids', $dados)) {
            $tipo->ramosAtividade()->sync(RamoAtividade::whereIn('uuid', $dados['ramos_atividade_uuids'] ?? [])->pluck('id'));
        }
    }

    /**
     * Lojas onde a Ação vale, pro "Enviar agora" (docs/66) quando o escopo depende do Mix
     * (LINHA_AUSENTE) ou há filtro por ramo (docs/80). Campanha fica de fora: depende da visita.
     */
    public function lojasDaAcao(TipoRegistro $tipoRegistro): JsonResponse
    {
        $tipoRegistro->load(['pontosVenda:id', 'redesLojas:id', 'ramosAtividade:id', 'escopoSecao']);
        $escopo = $tipoRegistro->acao_obrigatoria ? $tipoRegistro->escopo_acao : null;
        $ramos = $tipoRegistro->ramosAtividade->pluck('id');
        $lojas = $tipoRegistro->pontosVenda->pluck('id');
        $redes = $tipoRegistro->redesLojas->pluck('id');

        $pontosVenda = PontoVenda::query()
            ->where('ativo', true)
            ->when($ramos->isNotEmpty(), fn ($q) => $q->where(fn ($q) => $q->whereIn('ramo_atividade_id', $ramos)->orWhereNull('ramo_atividade_id')))
            ->when($escopo === EscopoAcaoTipoRegistro::CONTRATO, fn ($q) => $q->whereHas('contratosAtivos'))
            ->when(
                $escopo === EscopoAcaoTipoRegistro::LOJA_REDE && ($lojas->isNotEmpty() || $redes->isNotEmpty()),
                fn ($q) => $q->where(fn ($q) => $q->whereIn('id', $lojas)->orWhereIn('rede_loja_id', $redes)),
            )
            ->when($escopo === EscopoAcaoTipoRegistro::LINHA_AUSENTE, fn ($q) => $q->with([
                'sortimento' => fn ($s) => $s->whereNull('status_aprovacao')
                    ->with(['produto:id,secao_id,departamento_id,marca_id', 'secao:id,departamento_id']),
            ]))
            ->orderBy('fantasia')
            ->get(['id', 'uuid', 'fantasia']);

        if ($escopo === EscopoAcaoTipoRegistro::LINHA_AUSENTE) {
            $pontosVenda = $pontosVenda->filter(fn (PontoVenda $pdv) => LinhaAusente::valeComMix($tipoRegistro, $pdv->sortimento));
        }

        return response()->json([
            'pontos_venda' => $pontosVenda->map(fn (PontoVenda $pdv) => ['id' => $pdv->uuid, 'fantasia' => $pdv->fantasia])->values(),
        ]);
    }

    /**
     * Conversão do levantamento de linha ausente (docs/80 §5.3): das lojas onde este formulário
     * foi respondido até o fim do período, quantas passaram a ter a linha no Mix dentro do
     * período, depois do primeiro levantamento. "Entrou" = item aprovado do Mix que cobre o
     * recorte, criado nesse intervalo (o Mix é hard delete: item removido depois some da conta).
     */
    /**
     * "Quem ocupa o espaço" (docs/85 §3.4): por marca + embalagem, preço médio, R$ por litro/kg
     * (do mais barato), lojas e circunstâncias, no período. Só formulário por marca concorrente.
     */
    public function concorrentes(Request $request, TipoRegistro $tipoRegistro): JsonResponse
    {
        abort_unless($tipoRegistro->porMarca(), 422, 'Só formulários por marca concorrente têm esta visão.');
        $dados = $request->validate([
            'inicio' => ['required', 'date'],
            'fim' => ['required', 'date', 'after_or_equal:inicio'],
        ]);
        $tipoRegistro->load('campos');

        $registros = VisitaRegistro::query()
            ->where('tipo_registro_id', $tipoRegistro->id)
            ->whereNull('cancelado_em')
            ->whereBetween('created_at', [Carbon::parse($dados['inicio'])->startOfDay(), Carbon::parse($dados['fim'])->endOfDay()])
            ->with(['marca:id,uuid,descricao,status_aprovacao', 'visita:id,ponto_venda_id'])
            ->get();

        return response()->json(ConcorrentesPorMarca::resumo($tipoRegistro, $registros));
    }

    public function conversao(Request $request, TipoRegistro $tipoRegistro): JsonResponse
    {
        abort_if($tipoRegistro->escopo_acao !== EscopoAcaoTipoRegistro::LINHA_AUSENTE, 422, 'Só formulários de linha ausente têm conversão.');
        $dados = $request->validate([
            'inicio' => ['required', 'date'],
            'fim' => ['required', 'date', 'after_or_equal:inicio'],
        ]);
        $inicio = Carbon::parse($dados['inicio'])->startOfDay();
        $fim = Carbon::parse($dados['fim'])->endOfDay();
        $tipoRegistro->load('escopoSecao');

        $primeiros = VisitaRegistro::query()
            ->join('visitas', 'visitas.id', '=', 'visita_registros.visita_id')
            ->where('visita_registros.tipo_registro_id', $tipoRegistro->id)
            ->whereNull('visita_registros.cancelado_em')
            ->where('visita_registros.created_at', '<=', $fim)
            ->groupBy('visitas.ponto_venda_id')
            ->selectRaw('visitas.ponto_venda_id, min(visita_registros.created_at) as primeiro')
            ->pluck('primeiro', 'ponto_venda_id');

        $pontosVenda = PontoVenda::query()
            ->whereIn('id', $primeiros->keys())
            ->with(['sortimento' => fn ($s) => $s->whereNull('status_aprovacao')
                ->whereBetween('created_at', [$inicio, $fim])
                ->with(['produto:id,secao_id,departamento_id,marca_id', 'secao:id,departamento_id'])])
            ->orderBy('fantasia')
            ->get(['id', 'uuid', 'fantasia']);

        $convertidas = $pontosVenda->map(function (PontoVenda $pdv) use ($tipoRegistro, $primeiros) {
            $primeiro = Carbon::parse($primeiros[$pdv->id]);
            $entrou = $pdv->sortimento
                ->filter(fn ($item) => $item->created_at->gt($primeiro) && LinhaAusente::cobre($tipoRegistro, $item))
                ->min('created_at');

            return $entrou ? [
                'id' => $pdv->uuid,
                'fantasia' => $pdv->fantasia,
                'primeiro_levantamento_em' => $primeiro->toIso8601String(),
                'linha_entrou_em' => $entrou->toIso8601String(),
            ] : null;
        })->filter()->values();

        $monitoradas = $pontosVenda->count();

        return response()->json([
            'monitoradas' => $monitoradas,
            'convertidas' => $convertidas->count(),
            'percentual' => $monitoradas > 0 ? round($convertidas->count() * 100 / $monitoradas, 1) : null,
            'lojas_convertidas' => $convertidas,
        ]);
    }

    /**
     * Lista predefinida de produtos — só faz sentido com granularidade PRODUTO; qualquer outra
     * limpa a lista (mesmo raciocínio das pivôs do escopo LOJA_REDE). Ordem = ordem do array.
     */
    private function sincronizarProdutosPredefinidos(TipoRegistro $tipo, array $dados): void
    {
        if ($tipo->granularidade_padrao?->value !== 'PRODUTO' || $tipo->lista_produtos_origem === TipoRegistro::LISTA_PRODUTOS_MIX_LOJA) {
            $tipo->produtosPredefinidos()->detach();

            return;
        }
        if (! array_key_exists('produtos_uuids', $dados)) {
            return;
        }

        $uuids = array_values($dados['produtos_uuids'] ?? []);
        $ids = ProdutoAuditoria::whereIn('uuid', $uuids)->pluck('id', 'uuid');
        $sync = [];
        foreach ($uuids as $ordem => $uuid) {
            if (isset($ids[$uuid])) {
                $sync[$ids[$uuid]] = ['ordem' => $ordem];
            }
        }
        $tipo->produtosPredefinidos()->sync($sync);
    }

    /** Lista de marcas (docs/85) — mesmo raciocínio dos produtos: só com granularidade MARCA. */
    private function sincronizarMarcasPredefinidas(TipoRegistro $tipo, array $dados): void
    {
        if (! $tipo->porMarca()) {
            $tipo->marcasPredefinidas()->detach();

            return;
        }
        if (! array_key_exists('marcas_uuids', $dados)) {
            return;
        }

        $uuids = array_values($dados['marcas_uuids'] ?? []);
        $ids = MarcaAuditoria::whereIn('uuid', $uuids)->pluck('id', 'uuid');
        $sync = [];
        foreach ($uuids as $ordem => $uuid) {
            if (isset($ids[$uuid])) {
                $sync[$ids[$uuid]] = ['ordem' => $ordem];
            }
        }
        $tipo->marcasPredefinidas()->sync($sync);
    }

    /** Configuração do "ainda está lá?" na criação (docs/83 §5.1). */
    private static function camposAcompanhamento(array $dados): array
    {
        $acompanha = (bool) ($dados['acompanha'] ?? false);

        return [
            'acompanha' => $acompanha,
            'acompanha_gatilho_chave' => $acompanha ? ($dados['acompanha_gatilho_chave'] ?? null) : null,
            'acompanha_gatilho_valor' => $acompanha ? ($dados['acompanha_gatilho_valor'] ?? null) : null,
            'acompanha_intervalo_dias' => $dados['acompanha_intervalo_dias'] ?? 0,
            'acompanha_rotulo_continua' => $dados['acompanha_rotulo_continua'] ?? null,
            'acompanha_rotulo_acabou' => $dados['acompanha_rotulo_acabou'] ?? null,
            'acompanha_ao_continuar' => $dados['acompanha_ao_continuar'] ?? 'CONFIRMA',
            'acompanha_formulario_acabou_id' => ! empty($dados['acompanha_formulario_acabou_uuid'])
                ? TipoRegistro::where('uuid', $dados['acompanha_formulario_acabou_uuid'])->value('id')
                : null,
            'acompanha_prazo_dias' => $dados['acompanha_prazo_dias'] ?? null,
        ];
    }

    private function resolverCampanhaAuditoriaId(?string $campanhaUuid): ?int
    {
        if (! $campanhaUuid) {
            return null;
        }

        return CampanhaAuditoria::where('uuid', $campanhaUuid)->value('id');
    }

    public function destroy(TipoRegistro $tipoRegistro): JsonResponse
    {
        // Soft delete (ativo = false), nunca hard delete — mesmo padrão do resto da API.
        // Registros de visita já feitos com este tipo continuam intactos (tipo_registro_id não
        // é apagado, só marcado como indisponível pra novos registros).
        $tipoRegistro->update(['ativo' => false]);

        return response()->json(status: 204);
    }

    /**
     * Sequência de exibição — troca a `ordem` deste tipo com a do vizinho (anterior/seguinte,
     * conforme `direcao`) em vez de expor o número cru pro front mexer direto (evita duas linhas
     * acabando com a mesma ordem por engano). Sem vizinho nessa direção (já é o primeiro/último),
     * não faz nada — não é erro, só não tem pra onde mover. Ver docs/03-ADMIN-WEB.md.
     */
    public function mover(MoverTipoRegistroRequest $request, TipoRegistro $tipoRegistro): JsonResponse
    {
        $direcao = $request->validated('direcao');

        $vizinho = $direcao === 'cima'
            ? TipoRegistro::where('ordem', '<', $tipoRegistro->ordem)->orderByDesc('ordem')->first()
            : TipoRegistro::where('ordem', '>', $tipoRegistro->ordem)->orderBy('ordem')->first();

        if ($vizinho) {
            DB::transaction(function () use ($tipoRegistro, $vizinho): void {
                $ordemAtual = $tipoRegistro->ordem;
                $tipoRegistro->update(['ordem' => $vizinho->ordem]);
                $vizinho->update(['ordem' => $ordemAtual]);
            });
        }

        return response()->json([
            'tipo_registro' => new TipoRegistroResource(
                $tipoRegistro->fresh()->load([...self::RELACOES_CAMPOS, 'campanhaAuditoria', 'excecoesGranularidade.secao', ...self::RELACOES_ESCOPO]),
            ),
        ]);
    }

    /**
     * Substitui a lista de campos inteira (delete-all + recreate) em vez de tentar casar item a
     * item — mais simples que reconciliar por chave/id, e a lista costuma ser pequena (poucos
     * campos por tipo). A ordem de exibição no formulário é a própria ordem do array recebido.
     *
     * Duas passadas por causa do campo condicional (`depende_de_chave`, ver decisão 7 de
     * docs/20-FORMULARIO-DINAMICO-CAMPANHA.md): o `id` do campo pai só existe depois de criado,
     * então a 1ª passada cria todos os campos (sem a dependência), a 2ª resolve
     * `depende_de_chave` → `depende_de_campo_id` já com todos os ids conhecidos.
     * `StoreTipoRegistroRequest::withValidator` garante que `depende_de_chave` (quando presente)
     * aponta pra uma chave que existe neste mesmo array, então o `$idsPorChave[...]` abaixo
     * nunca fica sem match.
     */
    private function sincronizarCampos(TipoRegistro $tipo, array $campos): void
    {
        CampoTipoRegistro::where('tipo_registro_id', $tipo->id)->delete();

        $idsPorChave = [];
        $criados = [];
        foreach (array_values($campos) as $indice => $campo) {
            // Mix que segue a escolha do promotor não guarda recorte fixo (docs/79 §4.1).
            $segueEscolha = (bool) ($campo['sortimento_segue_escolha'] ?? false);
            if ($segueEscolha) {
                unset($campo['sortimento_tipo_vinculo'], $campo['sortimento_secao_uuid'], $campo['sortimento_departamento_uuid'], $campo['sortimento_marca_uuid']);
            }

            $criado = CampoTipoRegistro::create([
                'tipo_registro_id' => $tipo->id,
                'chave' => $campo['chave'],
                'rotulo' => $campo['rotulo'],
                'tipo_campo' => $campo['tipo_campo'],
                'opcoes' => $campo['opcoes'] ?? null,
                'obrigatorio' => $campo['obrigatorio'] ?? false,
                'ordem' => $indice,
                'limite_dias_retroativos' => $campo['limite_dias_retroativos'] ?? null,
                // Só usado quando tipo_campo = SORTIMENTO (decisão 3 de
                // docs/20-FORMULARIO-DINAMICO-CAMPANHA.md) — fica tudo NULL/false pros demais tipos.
                'sortimento_origem' => $campo['sortimento_origem'] ?? null,
                'sortimento_tipo_vinculo' => $campo['sortimento_tipo_vinculo'] ?? null,
                'sortimento_secao_id' => isset($campo['sortimento_secao_uuid'])
                    ? SecaoAuditoria::where('uuid', $campo['sortimento_secao_uuid'])->value('id')
                    : null,
                'sortimento_departamento_id' => isset($campo['sortimento_departamento_uuid'])
                    ? DepartamentoAuditoria::where('uuid', $campo['sortimento_departamento_uuid'])->value('id')
                    : null,
                'sortimento_marca_id' => isset($campo['sortimento_marca_uuid'])
                    ? MarcaAuditoria::where('uuid', $campo['sortimento_marca_uuid'])->value('id')
                    : null,
                'sortimento_segue_escolha' => $segueEscolha,
                'confirmar_ruptura_ausentes' => $campo['confirmar_ruptura_ausentes'] ?? false,
            ]);

            // Lista curada (sortimento_origem = FIXO) — mesmo raciocínio de "tipo planograma".
            if (($campo['sortimento_origem'] ?? null) === 'FIXO' && ! empty($campo['sortimento_produtos_uuids'])) {
                $produtoIds = ProdutoAuditoria::whereIn('uuid', $campo['sortimento_produtos_uuids'])->pluck('id');
                $criado->produtosFixos()->sync($produtoIds);
            }

            $idsPorChave[$campo['chave']] = $criado->id;
            $criados[] = [$criado, $campo['depende_de_chave'] ?? null, $campo['depende_de_valor'] ?? null];
        }

        foreach ($criados as [$criado, $dependeDeChave, $dependeDeValor]) {
            if ($dependeDeChave !== null) {
                $criado->update([
                    'depende_de_campo_id' => $idsPorChave[$dependeDeChave] ?? null,
                    'depende_de_valor' => $dependeDeValor,
                ]);
            }
        }
    }

    /**
     * Duplicar (decisão 6 de docs/20-FORMULARIO-DINAMICO-CAMPANHA.md) — clona um `TipoRegistro`
     * existente com todos os campos (inclusive condicional e sortimento) como ponto de partida
     * de um formulário novo; cada cópia fica independente depois, sem "modelo vivo" sincronizado.
     * Ação obrigatória/campanha NUNCA são copiadas — a cópia nasce solta, o gestor decide se e
     * onde vincular (evita uma cópia "roubar" a pendência obrigatória da campanha original).
     */
    public function duplicar(TipoRegistro $tipoRegistro): JsonResponse
    {
        $tipoRegistro->load(['campos.dependeDe', 'campos.produtosFixos', 'excecoesGranularidade']);

        $copia = DB::transaction(function () use ($tipoRegistro) {
            $copia = TipoRegistro::create([
                'descricao' => $tipoRegistro->descricao.' (cópia)',
                'icone' => $tipoRegistro->icone,
                'ordem' => (TipoRegistro::max('ordem') ?? -1) + 1,
                'exige_foto' => $tipoRegistro->exige_foto,
                'permite_vincular_catalogo' => $tipoRegistro->permite_vincular_catalogo,
                'acao_obrigatoria' => false,
                'escopo_acao' => null,
                'campanha_auditoria_id' => null,
                // O recorte de LINHA_AUSENTE e os ramos são da "forma" do formulário, mas só valem com
                // Ação obrigatória — que a cópia nunca herda. Ficam vazios, como as pivôs de LOJA_REDE.
                'granularidade_padrao' => $tipoRegistro->granularidade_padrao,
                'escolhe_marca' => $tipoRegistro->escolhe_marca,
                'escolhe_departamento' => $tipoRegistro->escolhe_departamento,
                'escolhe_secao' => $tipoRegistro->escolhe_secao,
                'lista_recorte' => $tipoRegistro->lista_recorte,
                'filtra_marca' => $tipoRegistro->filtra_marca,
                'permite_marca_nova' => $tipoRegistro->permite_marca_nova,
                'lista_produtos_origem' => $tipoRegistro->lista_produtos_origem,
                'varias_embalagens' => $tipoRegistro->varias_embalagens,
                'acompanha' => $tipoRegistro->acompanha,
                'acompanha_gatilho_chave' => $tipoRegistro->acompanha_gatilho_chave,
                'acompanha_gatilho_valor' => $tipoRegistro->acompanha_gatilho_valor,
                'acompanha_intervalo_dias' => $tipoRegistro->acompanha_intervalo_dias,
                'acompanha_rotulo_continua' => $tipoRegistro->acompanha_rotulo_continua,
                'acompanha_rotulo_acabou' => $tipoRegistro->acompanha_rotulo_acabou,
                'acompanha_ao_continuar' => $tipoRegistro->acompanha_ao_continuar,
                'acompanha_formulario_acabou_id' => $tipoRegistro->acompanha_formulario_acabou_id,
                'acompanha_prazo_dias' => $tipoRegistro->acompanha_prazo_dias,
                'intervalo_dias' => $tipoRegistro->intervalo_dias,
                'eh_ruptura' => $tipoRegistro->eh_ruptura,
                'eh_alerta' => $tipoRegistro->eh_alerta,
                'usa_pontuacao' => $tipoRegistro->usa_pontuacao,
                'xp_iniciativa' => $tipoRegistro->xp_iniciativa,
                'xp_bonus_acao' => $tipoRegistro->xp_bonus_acao,
                'xp_teto_por_visita' => $tipoRegistro->xp_teto_por_visita,
                'xp_intervalo_dias' => $tipoRegistro->xp_intervalo_dias,
                'exige_avaliacao' => $tipoRegistro->exige_avaliacao,
                'disponivel_registro_livre' => $tipoRegistro->disponivel_registro_livre,
            ]);

            $idsPorChave = [];
            $criados = [];
            foreach ($tipoRegistro->campos as $original) {
                $criado = CampoTipoRegistro::create([
                    'tipo_registro_id' => $copia->id,
                    'chave' => $original->chave,
                    'rotulo' => $original->rotulo,
                    'tipo_campo' => $original->tipo_campo,
                    'opcoes' => $original->opcoes,
                    'obrigatorio' => $original->obrigatorio,
                    'ordem' => $original->ordem,
                    'limite_dias_retroativos' => $original->limite_dias_retroativos,
                    'sortimento_origem' => $original->sortimento_origem,
                    'sortimento_tipo_vinculo' => $original->sortimento_tipo_vinculo,
                    'sortimento_secao_id' => $original->sortimento_secao_id,
                    'sortimento_departamento_id' => $original->sortimento_departamento_id,
                    'sortimento_marca_id' => $original->sortimento_marca_id,
                    'sortimento_segue_escolha' => $original->sortimento_segue_escolha,
                    'confirmar_ruptura_ausentes' => $original->confirmar_ruptura_ausentes,
                ]);
                $criado->produtosFixos()->sync($original->produtosFixos->pluck('id'));

                $idsPorChave[$original->chave] = $criado->id;
                $criados[] = [$criado, $original->dependeDe?->chave, $original->depende_de_valor];
            }

            foreach ($criados as [$criado, $dependeDeChave, $dependeDeValor]) {
                if ($dependeDeChave !== null) {
                    $criado->update([
                        'depende_de_campo_id' => $idsPorChave[$dependeDeChave] ?? null,
                        'depende_de_valor' => $dependeDeValor,
                    ]);
                }
            }

            foreach ($tipoRegistro->excecoesGranularidade as $excecao) {
                TipoRegistroSecaoExcecao::create([
                    'tipo_registro_id' => $copia->id,
                    'secao_auditoria_id' => $excecao->secao_auditoria_id,
                    'granularidade' => $excecao->granularidade,
                ]);
            }

            // Lista predefinida de produtos vai junto (é parte da "forma" do formulário, diferente
            // de Ação obrigatória/campanha, que a cópia nunca herda).
            $copia->produtosPredefinidos()->sync(
                $tipoRegistro->produtosPredefinidos()->get()->mapWithKeys(fn ($p) => [$p->id => ['ordem' => $p->pivot->ordem]])->all(),
            );
            $copia->marcasPredefinidas()->sync(
                $tipoRegistro->marcasPredefinidas()->get()->mapWithKeys(fn ($m) => [$m->id => ['ordem' => $m->pivot->ordem]])->all(),
            );

            return $copia;
        });

        $copia->load([...self::RELACOES_CAMPOS, 'campanhaAuditoria', 'excecoesGranularidade.secao', ...self::RELACOES_ESCOPO]);

        return response()->json([
            'tipo_registro' => new TipoRegistroResource($copia),
        ], 201);
    }

    /**
     * Mesmo padrão de sincronizarCampos: substitui a lista inteira de exceções (delete-all +
     * recreate) em vez de reconciliar item a item — lista pequena, sempre enviada por completo.
     * Ver docs/16-GRANULARIDADE-CHECKLIST-AUDITORIA.md §4.
     */
    private function sincronizarExcecoesGranularidade(TipoRegistro $tipo, array $excecoes): void
    {
        TipoRegistroSecaoExcecao::where('tipo_registro_id', $tipo->id)->delete();

        foreach ($excecoes as $excecao) {
            $secaoId = SecaoAuditoria::where('uuid', $excecao['secao_uuid'])->value('id');
            if (! $secaoId) {
                continue;
            }

            TipoRegistroSecaoExcecao::create([
                'tipo_registro_id' => $tipo->id,
                'secao_auditoria_id' => $secaoId,
                'granularidade' => $excecao['granularidade'],
            ]);
        }
    }
}

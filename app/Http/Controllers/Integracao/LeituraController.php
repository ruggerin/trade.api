<?php

namespace App\Http\Controllers\Integracao;

use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureIntegracao;
use App\Models\ImagemRegistro;
use App\Models\OrdemServico;
use App\Models\Pedido;
use App\Models\PontoVenda;
use App\Models\ProdutoAuditoria;
use App\Models\SortimentoPontoVenda;
use App\Models\Supervisor;
use App\Models\Usuario;
use App\Models\Vendedor;
use App\Models\Visita;
use App\Models\VisitaRegistro;
use App\Models\XpLancamento;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Leitura da API de integração (docs/96 §7.1), pra BI. Todas as listas seguem o mesmo contrato:
 *
 * - paginação por cursor (`?cursor=…&limit=500`, máximo 1000), estável com dado chegando;
 * - leitura incremental: `?updated_since=2026-10-22T00:00:00Z` traz só o que mudou desde então,
 *   em ordem de alteração, então o BI guarda o último `alterado_em` e pede dali na próxima vez;
 * - filtros `from`/`to` (data principal do recurso), `loja` e `promotor` (uuid) onde fazem sentido;
 * - só uuid (nunca o id interno), datas ISO 8601 em UTC e o fuso da empresa em `timezone`.
 *
 * `v1` não quebra: campo novo pode aparecer, campo existente não muda nem some (§7.4).
 * O escopo de cada rota é conferido no middleware (routes/api.php); a empresa, pelo BelongsToEmpresa
 * com a integração autenticada.
 */
class LeituraController extends Controller
{
    private const LIMITE_PADRAO = 500;

    private const LIMITE_MAXIMO = 1000;

    /** Validade do link de uma foto (docs/96 §7.1: nunca o arquivo no corpo). */
    private const MINUTOS_LINK_FOTO = 30;

    public function visitas(Request $request): JsonResponse
    {
        $query = Visita::query()
            ->with(['pontoVenda:id,uuid', 'usuario:id,uuid', 'ordemServico:id,uuid'])
            ->when($request->query('loja'), fn ($q, $uuid) => $q->whereHas('pontoVenda', fn ($p) => $p->where('uuid', $uuid)))
            ->when($request->query('promotor'), fn ($q, $uuid) => $q->whereHas('usuario', fn ($u) => $u->where('uuid', $uuid)));

        return $this->listar($request, $query, 'inicio_data', fn (Visita $v) => [
            'uuid' => $v->uuid,
            'loja_uuid' => $v->pontoVenda?->uuid,
            'promotor_uuid' => $v->usuario?->uuid,
            'ordem_servico_uuid' => $v->ordemServico?->uuid,
            'status' => $v->status?->value,
            'inicio' => $this->data($v->inicio_data),
            'fim' => $this->data($v->fim_data),
            'inicio_distancia_metros' => $v->inicio_distancia_metros,
            'fim_distancia_metros' => $v->fim_distancia_metros,
            'checkout_tipo' => $v->checkout_tipo?->value,
            'afastamento_qtd' => $v->afastamento_qtd,
            'afastamento_minutos' => $v->afastamento_minutos,
            'obrigatorias_feitas' => $v->obrigatorias_feitas,
            'obrigatorias_total' => $v->obrigatorias_total,
            'alterado_em' => $this->data($v->updated_at),
        ]);
    }

    public function registros(Request $request): JsonResponse
    {
        return $this->listarRegistros($request, $this->queryRegistros($request), fn (VisitaRegistro $r) => [
            ...$this->registroBase($r),
            'tipo_registro' => $r->tipoRegistro?->descricao,
            'observacao' => $r->observacao,
            'campos' => $r->valores_campos,
            'fotos' => $r->imagens->map(fn (ImagemRegistro $img) => [
                'uuid' => $img->uuid,
                'url' => $this->linkFoto($img),
                // Pergunta FOTO que a foto responde (null = foto livre) e a captura (docs/98).
                'pergunta' => $img->pivot?->campo_chave,
                'capturada_em' => $img->capturada_em?->toIso8601String(),
            ])->values(),
        ]);
    }

    public function rupturas(Request $request): JsonResponse
    {
        $query = $this->queryRegistros($request)->where('visita_registros.ruptura', true);

        return $this->listarRegistros($request, $query, fn (VisitaRegistro $r) => [
            ...$this->registroBase($r),
            'resolvida_em' => $this->data($r->alerta_resolvido_em),
            'motivo_resolucao' => $r->motivoResolucao?->descricao ?? $r->alerta_motivo_texto,
        ]);
    }

    public function ordensServico(Request $request): JsonResponse
    {
        $query = OrdemServico::query()
            ->with(['pontoVenda:id,uuid', 'usuario:id,uuid', 'visita:id,uuid'])
            ->when($request->query('loja'), fn ($q, $uuid) => $q->whereHas('pontoVenda', fn ($p) => $p->where('uuid', $uuid)))
            ->when($request->query('promotor'), fn ($q, $uuid) => $q->whereHas('usuario', fn ($u) => $u->where('uuid', $uuid)));

        return $this->listar($request, $query, 'prazo_inicio', fn (OrdemServico $o) => [
            'uuid' => $o->uuid,
            'loja_uuid' => $o->pontoVenda?->uuid,
            'promotor_uuid' => $o->usuario?->uuid,
            'visita_uuid' => $o->visita?->uuid,
            'origem' => $o->origem?->value,
            'status' => $o->status?->value,
            'obrigatoria' => $o->obrigatoria,
            'prioridade' => $o->prioridade?->value,
            'prazo_inicio' => $this->data($o->prazo_inicio),
            'prazo_fim' => $this->data($o->prazo_fim),
            'horario_previsto' => $o->horario_previsto,
            'duracao_prevista_minutos' => $o->duracao_prevista_minutos,
            'xp_previsto' => $o->xp_previsto,
            'cancelada_em' => $this->data($o->cancelada_em),
            'alterado_em' => $this->data($o->updated_at),
        ]);
    }

    /**
     * Lançamentos de XP não têm updated_at: a "alteração" é a criação ou a resolução (aprovação,
     * contestação), então `updated_since` olha as duas e a ordem é pelo id.
     */
    public function xp(Request $request): JsonResponse
    {
        $dados = $this->validarLista($request);
        $query = XpLancamento::query()
            ->with(['usuario:id,uuid', 'visita:id,uuid', 'ordemServico:id,uuid'])
            ->when($dados['updated_since'] ?? null, fn ($q, $desde) => $q->where(fn ($w) => $w->where('created_at', '>=', $desde)->orWhere('resolvido_em', '>=', $desde)))
            ->when($dados['from'] ?? null, fn ($q, $de) => $q->where('competencia', '>=', $de))
            ->when($dados['to'] ?? null, fn ($q, $ate) => $q->where('competencia', '<=', $ate))
            ->when($request->query('promotor'), fn ($q, $uuid) => $q->whereHas('usuario', fn ($u) => $u->where('uuid', $uuid)))
            ->orderBy('id');

        return $this->paginar($request, $query, $dados, fn (XpLancamento $x) => [
            'uuid' => $x->uuid,
            'promotor_uuid' => $x->usuario?->uuid,
            'visita_uuid' => $x->visita?->uuid,
            'ordem_servico_uuid' => $x->ordemServico?->uuid,
            'natureza' => $x->natureza,
            'categoria' => $x->categoria,
            'pontos' => $x->pontos,
            'status' => $x->status,
            'competencia' => $x->competencia,
            'motivo' => $x->motivo,
            'criado_em' => $this->data($x->created_at),
            'resolvido_em' => $this->data($x->resolvido_em),
        ]);
    }

    public function lojas(Request $request): JsonResponse
    {
        $query = PontoVenda::query()->with(['redeLoja:id,descricao', 'ramoAtividade:id,descricao', 'vendedores:vendedores.id,codigo_externo']);

        return $this->listar($request, $query, 'created_at', fn (PontoVenda $p) => [
            'uuid' => $p->uuid,
            'codigo_externo' => $p->codigo_externo,
            'razao_social' => $p->razao_social,
            'fantasia' => $p->fantasia,
            'cnpj' => $p->cnpj,
            'endereco' => $p->endereco,
            'numero' => $p->numero,
            'bairro' => $p->bairro,
            'cidade' => $p->cidade,
            'uf' => $p->uf,
            'cep' => $p->cep,
            'latitude' => $p->latitude,
            'longitude' => $p->longitude,
            'telefone' => $p->telefone,
            'email' => $p->email,
            'rede' => $p->redeLoja?->descricao,
            'ramo_atividade' => $p->ramoAtividade?->descricao,
            'numero_checkouts' => $p->numero_checkouts,
            'vendedores' => $p->vendedores->pluck('codigo_externo')->values(),
            'ativo' => $p->ativo,
            'alterado_em' => $this->data($p->updated_at),
        ]);
    }

    /** Pedidos do ERP com itens, entregas, valor e faturamento (docs/61), pra cruzar no BI. */
    public function pedidos(Request $request): JsonResponse
    {
        $query = Pedido::query()
            ->with(['pontoVenda:id,uuid,codigo_externo', 'itens.produto:id,uuid', 'entregas'])
            ->when($request->query('loja'), fn ($q, $uuid) => $q->whereHas('pontoVenda', fn ($p) => $p->where('uuid', $uuid)));

        return $this->listar($request, $query, 'data_pedido', fn (Pedido $p) => [
            'uuid' => $p->uuid,
            'numero_pedido' => $p->numero_pedido,
            'numero_nf' => $p->numero_nf,
            'loja_uuid' => $p->pontoVenda?->uuid,
            'loja_codigo_externo' => $p->pontoVenda?->codigo_externo,
            'data_pedido' => $p->data_pedido?->toDateString(),
            'data_previsao_entrega' => $p->data_previsao_entrega?->toDateString(),
            'valor_total' => $p->valor_total,
            'valor_lucro_total' => $p->valor_lucro_total,
            'status_faturamento' => $p->status_faturamento instanceof \BackedEnum ? $p->status_faturamento->value : $p->status_faturamento,
            'faturado_em' => $p->faturado_em?->toDateString(),
            'observacao' => $p->observacao,
            'itens' => $p->itens->map(fn ($i) => [
                'codigo_externo_produto' => $i->codigo_externo_produto,
                'produto_uuid' => $i->produto?->uuid,
                'descricao_produto' => $i->descricao_produto,
                'quantidade' => $i->quantidade,
                'preco_unitario' => $i->preco_unitario,
                'subtotal' => $i->subtotal,
                'custo_unitario' => $i->custo_unitario,
                'valor_lucro' => $i->valor_lucro,
            ])->values(),
            'entregas' => $p->entregas->map(fn ($e) => [
                'data_entrega' => $this->data($e->data_entrega),
                'observacao' => $e->observacao,
            ])->values(),
            'alterado_em' => $this->data($p->updated_at),
        ]);
    }

    public function vendedores(Request $request): JsonResponse
    {
        $query = Vendedor::query()->with('supervisor:id,codigo_externo');

        return $this->listar($request, $query, 'created_at', fn (Vendedor $v) => [
            'uuid' => $v->uuid,
            'codigo_externo' => $v->codigo_externo,
            'nome' => $v->nome,
            'telefone' => $v->telefone,
            'email' => $v->email,
            'codigo_supervisor' => $v->supervisor?->codigo_externo ?? $v->codigo_supervisor,
            'ativo' => $v->ativo,
            'alterado_em' => $this->data($v->updated_at),
        ]);
    }

    public function supervisores(Request $request): JsonResponse
    {
        return $this->listar($request, Supervisor::query(), 'created_at', fn (Supervisor $s) => [
            'uuid' => $s->uuid,
            'codigo_externo' => $s->codigo_externo,
            'nome' => $s->nome,
            'telefone' => $s->telefone,
            'email' => $s->email,
            'ativo' => $s->ativo,
            'alterado_em' => $this->data($s->updated_at),
        ]);
    }

    /**
     * Carteira vendedor × loja, um par por linha. O vínculo removido some (não fica marcado como
     * excluído): pra conferir a carteira inteira, leia sem `updated_since`.
     */
    public function carteira(Request $request): JsonResponse
    {
        $dados = $this->validarLista($request);
        $empresaId = $request->user()->empresa_id;
        $query = DB::table('vendedor_ponto_venda as c')
            ->join('pontos_venda as pv', 'pv.id', '=', 'c.ponto_venda_id')
            ->join('vendedores as v', 'v.id', '=', 'c.vendedor_id')
            ->where('pv.empresa_id', $empresaId)
            ->when($dados['updated_since'] ?? null, fn ($q, $desde) => $q->where('c.updated_at', '>=', $desde))
            ->when($request->query('loja'), fn ($q, $uuid) => $q->where('pv.uuid', $uuid))
            ->orderBy('c.ponto_venda_id')
            ->orderBy('c.vendedor_id')
            ->select(['c.ponto_venda_id', 'c.vendedor_id', 'pv.uuid as loja_uuid', 'pv.codigo_externo as loja_codigo_externo', 'v.codigo_externo as vendedor_codigo_externo', 'c.updated_at']);

        $pagina = $query->cursorPaginate((int) ($dados['limit'] ?? self::LIMITE_PADRAO), ['*'], 'cursor', $dados['cursor'] ?? null);
        $itens = collect($pagina->items())->map(fn ($c) => [
            'loja_uuid' => $c->loja_uuid,
            'loja_codigo_externo' => $c->loja_codigo_externo,
            'vendedor_codigo_externo' => $c->vendedor_codigo_externo,
            'alterado_em' => $c->updated_at ? Carbon::parse($c->updated_at, 'UTC')->toIso8601ZuluString() : null,
        ])->values();

        return $this->resposta($request, $itens, $pagina->nextCursor()?->encode());
    }

    /**
     * Mix da loja (sortimento): produto, ou departamento/seção/marca inteiros. Item removido some
     * (como na tela); `status_aprovacao` null = valendo, PENDENTE/REJEITADO = sugestão do promotor.
     */
    public function mix(Request $request): JsonResponse
    {
        $query = SortimentoPontoVenda::query()
            ->whereHas('pontoVenda')
            ->with(['pontoVenda:id,uuid,codigo_externo', 'produto:id,uuid,codigo_externo', 'departamento:id,descricao', 'secao:id,descricao', 'marca:id,descricao'])
            ->when($request->query('loja'), fn ($q, $uuid) => $q->whereHas('pontoVenda', fn ($p) => $p->where('uuid', $uuid)));

        return $this->listar($request, $query, 'created_at', fn (SortimentoPontoVenda $s) => [
            'uuid' => $s->uuid,
            'loja_uuid' => $s->pontoVenda?->uuid,
            'loja_codigo_externo' => $s->pontoVenda?->codigo_externo,
            'tipo_item' => $s->tipo_item instanceof \BackedEnum ? $s->tipo_item->value : $s->tipo_item,
            'produto_uuid' => $s->produto?->uuid,
            'produto_codigo_externo' => $s->produto?->codigo_externo,
            'departamento' => $s->departamento?->descricao,
            'secao' => $s->secao?->descricao,
            'marca' => $s->marca?->descricao,
            'status_aprovacao' => $s->status_aprovacao instanceof \BackedEnum ? $s->status_aprovacao->value : $s->status_aprovacao,
            'alterado_em' => $this->data($s->updated_at),
        ]);
    }

    public function produtos(Request $request): JsonResponse
    {
        $query = ProdutoAuditoria::query()->with(['marca:id,descricao', 'departamento:id,descricao', 'secao:id,descricao']);

        return $this->listar($request, $query, 'created_at', fn (ProdutoAuditoria $p) => [
            'uuid' => $p->uuid,
            'codigo_externo' => $p->codigo_externo,
            'descricao' => $p->descricao,
            'codigo_barras' => $p->codigo_barras,
            'marca' => $p->marca?->descricao,
            'departamento' => $p->departamento?->descricao,
            'secao' => $p->secao?->descricao,
            'preco_tabela' => $p->preco_tabela !== null ? (float) $p->preco_tabela : null,
            'ativo' => $p->ativo,
            'alterado_em' => $this->data($p->updated_at),
        ]);
    }

    /** Só identificação (docs/96 §5, LGPD): nunca e-mail, telefone, CPF ou localização. */
    public function promotores(Request $request): JsonResponse
    {
        $query = Usuario::query()->with('supervisor:id,uuid');

        return $this->listar($request, $query, 'created_at', fn (Usuario $u) => [
            'uuid' => $u->uuid,
            'nome' => $u->nome,
            'codigo_externo' => $u->codigo_externo,
            'tipo' => $u->user_type?->value,
            'supervisor_uuid' => $u->supervisor?->uuid,
            'ativo' => $u->ativo,
            'alterado_em' => $this->data($u->updated_at),
        ]);
    }

    /** Link assinado e temporário de foto (gerado em `registros`); não usa token. */
    public function foto(string $imagem): StreamedResponse
    {
        $registro = ImagemRegistro::query()->where('uuid', $imagem)->firstOrFail();

        return Storage::disk(config('filesystems.default'))->response($registro->caminho);
    }

    private function queryRegistros(Request $request): Builder
    {
        return VisitaRegistro::query()
            // VisitaRegistro não tem empresa_id: a empresa vem pela visita (tenant-aware).
            ->whereHas('visita', function ($v) use ($request) {
                $v->when($request->query('loja'), fn ($q, $uuid) => $q->whereHas('pontoVenda', fn ($p) => $p->where('uuid', $uuid)))
                    ->when($request->query('promotor'), fn ($q, $uuid) => $q->whereHas('usuario', fn ($u) => $u->where('uuid', $uuid)));
            })
            ->with(['visita:id,uuid,ponto_venda_id,usuario_id', 'visita.pontoVenda:id,uuid', 'visita.usuario:id,uuid', 'produtoAuditoria:id,uuid,descricao', 'tipoRegistro:id,descricao', 'motivoResolucao:id,descricao', 'imagens']);
    }

    private function registroBase(VisitaRegistro $r): array
    {
        return [
            'uuid' => $r->uuid,
            'visita_uuid' => $r->visita?->uuid,
            'loja_uuid' => $r->visita?->pontoVenda?->uuid,
            'promotor_uuid' => $r->visita?->usuario?->uuid,
            'produto_uuid' => $r->produtoAuditoria?->uuid,
            'produto' => $r->produtoAuditoria?->descricao,
            'ruptura' => $r->ruptura,
            'criado_em' => $this->data($r->created_at),
            'cancelado_em' => $this->data($r->cancelado_em),
            'alterado_em' => $this->data($r->updated_at),
        ];
    }

    private function listarRegistros(Request $request, Builder $query, callable $formatar): JsonResponse
    {
        return $this->listar($request, $query, 'created_at', $formatar);
    }

    /** Lista padrão: `updated_since` em updated_at, `from`/`to` na coluna de data do recurso. */
    private function listar(Request $request, Builder $query, string $colunaData, callable $formatar): JsonResponse
    {
        $dados = $this->validarLista($request);
        $tabela = $query->getModel()->getTable();

        $query
            ->when($dados['updated_since'] ?? null, fn ($q, $desde) => $q->where("{$tabela}.updated_at", '>=', $desde))
            ->when($dados['from'] ?? null, fn ($q, $de) => $q->where("{$tabela}.{$colunaData}", '>=', $de))
            ->when($dados['to'] ?? null, fn ($q, $ate) => $q->where("{$tabela}.{$colunaData}", '<', \Carbon\Carbon::parse($ate)->addDay()->toDateString()))
            ->orderBy("{$tabela}.updated_at")
            ->orderBy("{$tabela}.id");

        return $this->paginar($request, $query, $dados, $formatar);
    }

    private function validarLista(Request $request): array
    {
        return $request->validate([
            'cursor' => ['nullable', 'string', 'max:500'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:'.self::LIMITE_MAXIMO],
            'updated_since' => ['nullable', 'date'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'loja' => ['nullable', 'uuid'],
            'promotor' => ['nullable', 'uuid'],
        ]);
    }

    private function paginar(Request $request, Builder $query, array $dados, callable $formatar): JsonResponse
    {
        $pagina = $query->cursorPaginate((int) ($dados['limit'] ?? self::LIMITE_PADRAO), ['*'], 'cursor', $dados['cursor'] ?? null);
        $itens = collect($pagina->items())->map(fn (Model $m) => $formatar($m))->values();

        return $this->resposta($request, $itens, $pagina->nextCursor()?->encode());
    }

    private function resposta(Request $request, \Illuminate\Support\Collection $itens, ?string $proximoCursor): JsonResponse
    {
        $request->attributes->set(EnsureIntegracao::ATRIBUTO_REGISTROS, $itens->count());

        return response()->json([
            'data' => $itens,
            'next_cursor' => $proximoCursor,
            'timezone' => $request->user()->empresa?->fuso ?? 'America/Sao_Paulo',
        ]);
    }

    private function data(mixed $valor): ?string
    {
        return $valor instanceof CarbonInterface ? $valor->copy()->utc()->toIso8601ZuluString() : null;
    }

    private function linkFoto(ImagemRegistro $imagem): string
    {
        // Assinatura sobre o caminho (relative): continua valendo atrás de proxy/CDN que muda o host.
        return url(URL::temporarySignedRoute('integracao.foto', now()->addMinutes(self::MINUTOS_LINK_FOTO), ['imagem' => $imagem->uuid], absolute: false));
    }
}

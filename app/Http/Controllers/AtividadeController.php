<?php

namespace App\Http\Controllers;

use App\Enums\Permissao;
use App\Enums\StatusVisita;
use App\Enums\UserType;
use App\Http\Resources\VisitaRegistroResource;
use App\Models\Parametro;
use App\Models\PontoVenda;
use App\Models\TipoRegistro;
use App\Models\Usuario;
use App\Models\Visita;
use App\Models\VisitaRegistro;
use App\Models\VisitaRegistroComentario;
use App\Support\Fuso;
use App\Support\RaioCheckin;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Painel de Atividades — feed único, cronológico, com tudo que rolou nas visitas (todos os
 * promotores, todas as lojas): o substituto do grupo de WhatsApp que o gestor usa hoje. Ver
 * docs/19-PAINEL-ATIVIDADES.md e a revisão de UX em docs/43-REVISAO-UX-PAINEL-ATIVIDADES.md.
 *
 * Não existe um "evento" unificado no banco — este endpoint junta as fontes (Visita, VisitaRegistro
 * de alerta, VisitaRegistro de formulário, comentário de promotor) e mescla em memória, já que o
 * volume esperado (uma empresa, normalmente filtrado por hoje/ontem) é pequeno. Paginado por
 * página (não cursor de verdade), mesmo `meta` de `GET /api/visitas`.
 */
class AtividadeController extends Controller
{
    private const POR_PAGINA = 20;

    // Prévia da conversa embutida no card (docs/43 §4 item 3) — só as últimas mensagens; o
    // restante abre sob demanda (e aí sim marca como lido, via ComentarioRegistroController).
    private const MENSAGENS_NA_PREVIA = 3;

    private const ALERTAS_NO_RESUMO = 6;

    private const VALORES_VERDADEIROS = ['1', 'true', 'sim', 'yes'];

    public function index(Request $request): JsonResponse
    {
        $this->autorizar($request);

        $usuarioId = $request->filled('usuario_uuid')
            ? Usuario::where('uuid', $request->string('usuario_uuid'))->value('id')
            : null;
        $pontoVendaId = $request->filled('ponto_venda_uuid')
            ? PontoVenda::where('uuid', $request->string('ponto_venda_uuid'))->value('id')
            : null;
        $tipoRegistroId = $request->filled('tipo_registro_uuid')
            ? TipoRegistro::where('uuid', $request->string('tipo_registro_uuid'))->value('id')
            : null;
        $apenasPendentes = $request->boolean('pendentes');
        $comFoto = $request->boolean('com_foto');

        // Filtro por tipo específico ou pela aba de pendências: só interessa o registro em si,
        // sem o ruído de check-in/checkout da visita.
        $somenteRegistros = $request->filled('tipo_registro_uuid') || $apenasPendentes;

        $eventos = $this->eventosDeAlerta($request, $usuarioId, $pontoVendaId, $tipoRegistroId, $apenasPendentes, $comFoto);

        if (! $apenasPendentes) {
            $eventos = $eventos->concat($this->eventosDeFormulario($request, $usuarioId, $pontoVendaId, $tipoRegistroId, $comFoto));
        }

        if (! $somenteRegistros) {
            $eventos = $eventos->concat($this->eventosDeVisita($request, $usuarioId, $pontoVendaId, $comFoto));

            if (! $comFoto) {
                // Resposta de promotor num registro que já é um card do feed aparece DENTRO da
                // conversa desse card — só vira linha própria quando o registro não está no feed
                // (alerta de outro dia, registro só de foto), ver docs/43 §6 decisão 4.
                // `registro` aqui é o VisitaRegistroResource — o uuid vem do model por baixo dele
                // (pluck('registro.id') devolveria o id interno bigint).
                $registrosNoFeed = $eventos
                    ->flatMap(fn (array $e) => match (true) {
                        isset($e['registro']) => [$e['registro']->resource->uuid],
                        isset($e['registros']) => $e['registros']->resource->pluck('uuid')->all(),
                        default => [],
                    })
                    ->values()
                    ->all();
                $eventos = $eventos->concat($this->eventosDeComentario($request, $usuarioId, $pontoVendaId, $registrosNoFeed));
            }
        }

        $eventos = $eventos->sortByDesc('ocorrido_em')->values();

        $pagina = max(1, $request->integer('page', 1));
        $total = $eventos->count();
        $ultimaPagina = max(1, (int) ceil($total / self::POR_PAGINA));

        return response()->json([
            'eventos' => $eventos->forPage($pagina, self::POR_PAGINA)->values(),
            'meta' => [
                'current_page' => $pagina,
                'last_page' => $ultimaPagina,
                'per_page' => self::POR_PAGINA,
                'total' => $total,
            ],
        ]);
    }

    /**
     * Coluna lateral + contadores do painel (docs/43 §4 item 4): quem está em loja agora, alertas
     * sem tratativa no período e respostas novas de promotor. Consultado por polling junto do feed
     * e pelo badge do menu.
     */
    public function resumo(Request $request): JsonResponse
    {
        $this->autorizar($request);
        $usuario = $request->user();

        // Datas locais no fuso da empresa (docs/50 §4.3) — default ontem+hoje desse fuso.
        $fuso = Fuso::daEmpresa($usuario->empresa);
        $inicio = $request->filled('data_inicio') ? $request->string('data_inicio')->toString() : Fuso::hoje($fuso)->subDay()->toDateString();
        $fim = $request->filled('data_fim') ? $request->string('data_fim')->toString() : Fuso::hoje($fuso)->toDateString();

        $emLoja = Visita::query()
            ->where('status', StatusVisita::ABERTA)
            ->with(['usuario', 'pontoVenda'])
            ->orderBy('inicio_data')
            ->get()
            ->map(fn (Visita $v) => [
                'visita_id' => $v->uuid,
                'usuario' => $this->usuarioParaEvento($v->usuario),
                'ponto_venda' => $v->pontoVenda ? ['id' => $v->pontoVenda->uuid, 'fantasia' => $v->pontoVenda->fantasia] : null,
                'desde' => $v->inicio_data,
            ]);

        // "Sem tratativa" = não resolvido e sem Plano de Ação em andamento (quem tem plano já está
        // sendo acompanhado em Planos de Ação, não precisa de você aqui).
        $alertas = VisitaRegistro::query()
            ->whereNull('cancelado_em')
            ->whereNull('alerta_resolvido_em')
            ->whereHas('tipoRegistro', fn ($q) => $q->where('eh_alerta', true))
            ->whereHas('visita')
            ->whereDoesntHave('planoAcaoAtivo')
            ->tap(fn ($q) => Fuso::filtrarPeriodo($q, 'created_at', $inicio, $fim, $fuso))
            ->with(['visita.pontoVenda', 'tipoRegistro', 'produtoAuditoria'])
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'em_loja' => $emLoja,
            'total_promotores' => Usuario::query()->where('user_type', UserType::PROMOTOR)->where('ativo', true)->count(),
            'alertas' => [
                'total' => $alertas->count(),
                'itens' => $alertas->take(self::ALERTAS_NO_RESUMO)->map(fn (VisitaRegistro $r) => [
                    'registro_id' => $r->uuid,
                    'visita_id' => $r->visita->uuid,
                    'tipo' => $r->tipoRegistro->descricao,
                    'produto' => $r->produtoAuditoria?->descricao,
                    'ponto_venda' => $r->visita->pontoVenda?->fantasia,
                    'ocorrido_em' => $r->created_at,
                ])->values(),
            ],
            'respostas_novas' => $this->respostasNovas($usuario),
            'requer_resolucao' => $this->requerResolucao(),
        ]);
    }

    private function autorizar(Request $request): void
    {
        if (! in_array($request->user()->user_type, [UserType::ADMIN, UserType::GESTOR], true)) {
            abort(403, 'O Painel de Atividades é só para ADMIN/GESTOR.');
        }
    }

    private function eventosDeVisita(Request $request, ?int $usuarioId, ?int $pontoVendaId, bool $comFoto): Collection
    {
        $raio = RaioCheckin::metros($request->user()->empresa);
        $veAfastamento = $request->user()->temPermissao(Permissao::RASTREAMENTO_TRAJETO);

        $visitas = Visita::query()
            ->with([
                'pontoVenda', 'usuario',
                // Fotos coletadas na visita, pro álbum do post de saída — mesma regra de exclusão de
                // cancelado_em das contagens abaixo. Reaproveita o VisitaRegistroResource inteiro
                // pra galeria mostrar tipo/produto/observação junto de cada foto.
                'registros' => fn ($q) => $q->whereNull('cancelado_em')->whereHas('imagens')
                    ->comContagemComentarios($request->user()->id)
                    ->with(['tipoRegistro.campos', 'produtoAuditoria', 'secao', 'departamento', 'marca', 'imagens']),
            ])
            ->withCount([
                'registros as registros_count' => fn ($q) => $q->whereNull('cancelado_em'),
                'registros as rupturas_count' => fn ($q) => $q->whereNull('cancelado_em')->where('ruptura', true),
            ])
            ->when($request->filled('usuario_uuid'), fn ($q) => $q->where('usuario_id', $usuarioId))
            ->when($request->filled('ponto_venda_uuid'), fn ($q) => $q->where('ponto_venda_id', $pontoVendaId))
            // Período por data local no fuso da empresa (docs/50 §4.3), não meia-noite UTC.
            ->tap(fn ($q) => Fuso::filtrarPeriodo($q, 'inicio_data', $request->input('data_inicio'), $request->input('data_fim'), Fuso::daEmpresa($request->user()->empresa)))
            ->get();

        $eventos = collect();

        foreach ($visitas as $visita) {
            $pontoVenda = $visita->pontoVenda ? ['id' => $visita->pontoVenda->uuid, 'fantasia' => $visita->pontoVenda->fantasia] : null;
            $usuario = $this->usuarioParaEvento($visita->usuario);
            $distancia = $visita->inicio_distancia_metros !== null ? (int) round((float) $visita->inicio_distancia_metros) : null;

            // Chegada não tem foto — some no filtro "Com foto".
            if (! $comFoto) {
                $eventos->push([
                    'id' => "chegada:{$visita->uuid}",
                    'tipo_evento' => 'VISITA_INICIADA',
                    'ocorrido_em' => $visita->inicio_data,
                    'visita' => ['id' => $visita->uuid],
                    'ponto_venda' => $pontoVenda,
                    'usuario' => $usuario,
                    // GPS do check-in — mesmo dado que já valida o raio no backend (regra de
                    // negócio 1, docs/02-API-BACKEND.md).
                    'localizacao' => [
                        'latitude' => (float) $visita->inicio_latitude,
                        'longitude' => (float) $visita->inicio_longitude,
                        'distancia_metros' => $distancia,
                        // Raio ATUAL da empresa (não é gravado por visita); limite desativado = nunca
                        // "fora". O feed destaca a chegada fora do raio em âmbar (docs/43 §2).
                        'fora_do_raio' => $distancia !== null && is_finite($raio) && $distancia > $raio,
                    ],
                ]);
            }

            if ($visita->fim_data !== null) {
                $totalFotos = $visita->registros->sum(fn (VisitaRegistro $r) => $r->imagens->count());
                if ($comFoto && $totalFotos === 0) {
                    continue;
                }

                $eventos->push([
                    'id' => "saida:{$visita->uuid}",
                    'tipo_evento' => 'VISITA_FINALIZADA',
                    'ocorrido_em' => $visita->fim_data,
                    'visita' => ['id' => $visita->uuid],
                    'ponto_venda' => $pontoVenda,
                    'usuario' => $usuario,
                    'resumo' => [
                        'registros' => $visita->registros_count,
                        'rupturas' => $visita->rupturas_count,
                        'duracao_minutos' => (int) round($visita->inicio_data->diffInMinutes($visita->fim_data, true)),
                        'total_fotos' => $totalFotos,
                    ],
                    // Saiu da loja durante a visita (docs/49) — resumo gravado um tempo depois do
                    // checkout; o card destaca em âmbar, como a chegada fora do raio.
                    'afastamento' => $veAfastamento && $visita->afastamento_qtd > 0 ? [
                        'qtd' => $visita->afastamento_qtd,
                        'minutos' => $visita->afastamento_minutos,
                        'max_metros' => $visita->afastamento_max_metros,
                    ] : null,
                    // setRelation('visita', ...) evita 1 query por registro só pra montar a url da
                    // imagem (mesmo truque de VisitaController::show).
                    'imagens' => VisitaRegistroResource::collection(
                        $visita->registros->each(fn (VisitaRegistro $r) => $r->setRelation('visita', $visita)),
                    ),
                ]);
            }
        }

        return $eventos;
    }

    /**
     * Comentários escritos por PROMOTOR em registros que NÃO são card do feed — o que o admin
     * precisa ver sem procurar. Os demais aparecem dentro da conversa do próprio card.
     *
     * @param  list<string>  $registrosNoFeed  uuids dos registros que já viraram card
     */
    private function eventosDeComentario(Request $request, ?int $usuarioId, ?int $pontoVendaId, array $registrosNoFeed): Collection
    {
        $comentarios = VisitaRegistroComentario::query()
            ->whereHas('usuario', fn ($q) => $q->where('user_type', UserType::PROMOTOR->value))
            ->whereHas('registro', fn ($q) => $q->whereNotIn('uuid', $registrosNoFeed))
            ->whereHas('registro.visita', function ($q) use ($request, $usuarioId, $pontoVendaId) {
                $q->when($request->filled('usuario_uuid'), fn ($q) => $q->where('usuario_id', $usuarioId))
                    ->when($request->filled('ponto_venda_uuid'), fn ($q) => $q->where('ponto_venda_id', $pontoVendaId));
            })
            // Período por data local no fuso da empresa (docs/50 §4.3), não meia-noite UTC.
            ->tap(fn ($q) => Fuso::filtrarPeriodo($q, 'created_at', $request->input('data_inicio'), $request->input('data_fim'), Fuso::daEmpresa($request->user()->empresa)))
            ->with([
                'usuario',
                'registro' => fn ($q) => $q->comContagemComentarios($request->user()->id),
                'registro.visita.pontoVenda', 'registro.tipoRegistro', 'registro.produtoAuditoria',
            ])
            ->get();

        return $comentarios->map(fn (VisitaRegistroComentario $c) => [
            'id' => "comentario:{$c->uuid}",
            'tipo_evento' => 'COMENTARIO',
            'ocorrido_em' => $c->created_at,
            'visita' => ['id' => $c->registro->visita->uuid],
            'ponto_venda' => $c->registro->visita->pontoVenda
                ? ['id' => $c->registro->visita->pontoVenda->uuid, 'fantasia' => $c->registro->visita->pontoVenda->fantasia]
                : null,
            'usuario' => $this->usuarioParaEvento($c->usuario),
            'comentario' => [
                'id' => $c->uuid,
                'texto' => $c->texto,
                'registro_id' => $c->registro->uuid,
                'tipo_registro' => $c->registro->tipoRegistro?->descricao,
                'produto' => $c->registro->produtoAuditoria?->descricao,
                'comentarios_count' => (int) $c->registro->comentarios_count,
                'comentarios_novos' => (int) $c->registro->comentarios_novos,
            ],
        ]);
    }

    private function eventosDeAlerta(
        Request $request,
        ?int $usuarioId,
        ?int $pontoVendaId,
        ?int $tipoRegistroId,
        bool $apenasPendentes,
        bool $comFoto,
    ): Collection {
        $registros = $this->queryRegistros($request, $usuarioId, $pontoVendaId, $tipoRegistroId, $comFoto)
            ->whereHas('tipoRegistro', fn ($q) => $q->where('eh_alerta', true))
            ->when($apenasPendentes, fn ($q) => $q->whereNull('alerta_resolvido_em'))
            ->with(['resolvidoPor', 'planoAcaoAtivo'])
            ->get();

        return $this->eventosDeRegistros($request, $registros, 'ALERTA');
    }

    /**
     * Formulário (não alerta) respondido — ex.: "mano respondeu Pesquisa de Preço". Os registros
     * do MESMO formulário na MESMA visita viram um post só (uma pesquisa de preço de 12 produtos é
     * 1 item do feed, não 12), com as respostas em tabela. Só entra registro com ao menos uma
     * resposta; registro só de foto (Antes/Depois) já aparece no álbum da saída. Ver docs/43 §6
     * decisão 1 e §8.
     */
    private function eventosDeFormulario(
        Request $request,
        ?int $usuarioId,
        ?int $pontoVendaId,
        ?int $tipoRegistroId,
        bool $comFoto,
    ): Collection {
        $registros = $this->queryRegistros($request, $usuarioId, $pontoVendaId, $tipoRegistroId, $comFoto)
            ->whereHas('tipoRegistro', fn ($q) => $q->where('eh_alerta', false))
            ->whereNotNull('valores_campos')
            ->whereRaw("valores_campos::text NOT IN ('[]', '{}', 'null')")
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        $conversas = $this->previasDeConversa($request->user(), $registros);

        return $registros
            ->groupBy(fn (VisitaRegistro $r) => "{$r->visita_id}:{$r->tipo_registro_id}")
            ->map(function (Collection $grupo) use ($conversas) {
                /** @var VisitaRegistro $primeiro */
                $primeiro = $grupo->first();
                $visita = $primeiro->visita;

                return [
                    'id' => "formulario:{$visita->uuid}:{$primeiro->tipoRegistro->uuid}",
                    'tipo_evento' => 'FORMULARIO',
                    // Hora da última resposta — é quando o formulário "fechou".
                    'ocorrido_em' => $grupo->max('created_at'),
                    'visita' => ['id' => $visita->uuid],
                    'ponto_venda' => $visita->pontoVenda
                        ? ['id' => $visita->pontoVenda->uuid, 'fantasia' => $visita->pontoVenda->fantasia]
                        : null,
                    'usuario' => $this->usuarioParaEvento($visita->usuario),
                    'tipo_registro' => ['id' => $primeiro->tipoRegistro->uuid, 'descricao' => $primeiro->tipoRegistro->descricao],
                    'registros' => VisitaRegistroResource::collection($grupo->values()),
                    // Prévia da conversa de cada registro (uuid => mensagens) — só os que têm conversa.
                    'conversas' => (object) $grupo
                        ->filter(fn (VisitaRegistro $r) => isset($conversas[$r->id]))
                        ->mapWithKeys(fn (VisitaRegistro $r) => [$r->uuid => $conversas[$r->id]])
                        ->all(),
                ];
            })
            ->values();
    }

    private function queryRegistros(Request $request, ?int $usuarioId, ?int $pontoVendaId, ?int $tipoRegistroId, bool $comFoto): Builder
    {
        return VisitaRegistro::query()
            ->whereNull('cancelado_em')
            ->whereHas('visita', function ($q) use ($request, $usuarioId, $pontoVendaId) {
                $q->when($request->filled('usuario_uuid'), fn ($q) => $q->where('usuario_id', $usuarioId))
                    ->when($request->filled('ponto_venda_uuid'), fn ($q) => $q->where('ponto_venda_id', $pontoVendaId));
            })
            ->when($request->filled('tipo_registro_uuid'), fn ($q) => $q->where('tipo_registro_id', $tipoRegistroId))
            ->when($comFoto, fn ($q) => $q->whereHas('imagens'))
            // Período por data local no fuso da empresa (docs/50 §4.3), não meia-noite UTC.
            ->tap(fn ($q) => Fuso::filtrarPeriodo($q, 'created_at', $request->input('data_inicio'), $request->input('data_fim'), Fuso::daEmpresa($request->user()->empresa)))
            ->comContagemComentarios($request->user()->id)
            ->with([
                'visita.pontoVenda', 'visita.usuario', 'tipoRegistro.campos', 'produtoAuditoria',
                'secao', 'departamento', 'marca', 'imagens',
            ]);
    }

    /** @param  Collection<int, VisitaRegistro>  $registros */
    private function eventosDeRegistros(Request $request, Collection $registros, string $tipoEvento): Collection
    {
        $conversas = $this->previasDeConversa($request->user(), $registros);

        return $registros->map(fn (VisitaRegistro $registro) => [
            'id' => "alerta:{$registro->uuid}",
            'tipo_evento' => $tipoEvento,
            'ocorrido_em' => $registro->created_at,
            'visita' => ['id' => $registro->visita->uuid],
            'ponto_venda' => $registro->visita->pontoVenda
                ? ['id' => $registro->visita->pontoVenda->uuid, 'fantasia' => $registro->visita->pontoVenda->fantasia]
                : null,
            'usuario' => $this->usuarioParaEvento($registro->visita->usuario),
            'registro' => new VisitaRegistroResource($registro),
            'conversa' => $conversas[$registro->id] ?? [],
        ]);
    }

    /**
     * Últimas mensagens de cada registro, pra conversa embutida no card. NÃO marca como lido —
     * rolar o painel não é "ler" (docs/43 §4 item 3); `novo` usa a mesma regra do badge.
     *
     * @param  Collection<int, VisitaRegistro>  $registros
     * @return array<int, list<array<string, mixed>>> registro_id => mensagens (mais antiga primeiro)
     */
    private function previasDeConversa(Usuario $leitor, Collection $registros): array
    {
        $ids = $registros->filter(fn (VisitaRegistro $r) => (int) $r->comentarios_count > 0)->pluck('id');
        if ($ids->isEmpty()) {
            return [];
        }

        $leituras = DB::table('visita_registro_comentario_leituras')
            ->where('usuario_id', $leitor->id)
            ->whereIn('visita_registro_id', $ids)
            ->pluck('lido_em', 'visita_registro_id');

        return VisitaRegistroComentario::query()
            ->whereIn('visita_registro_id', $ids)
            ->with('usuario')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->groupBy('visita_registro_id')
            ->map(fn (Collection $comentarios, $registroId) => $comentarios
                ->slice(-self::MENSAGENS_NA_PREVIA)
                ->map(function (VisitaRegistroComentario $c) use ($leitor, $leituras, $registroId) {
                    $lidoEm = isset($leituras[$registroId]) ? Carbon::parse($leituras[$registroId]) : null;
                    $meu = $c->usuario_id === $leitor->id;

                    return [
                        'id' => $c->uuid,
                        'texto' => $c->texto,
                        'criado_em' => $c->created_at,
                        'autor' => $this->usuarioParaEvento($c->usuario),
                        'meu' => $meu,
                        'novo' => ! $meu && ($lidoEm === null || $c->created_at->gt($lidoEm)),
                    ];
                })
                ->values()
                ->all())
            ->all();
    }

    /** Mesma regra do badge de ComentarioRegistroController::naoLidos, só a contagem. */
    private function respostasNovas(Usuario $usuario): int
    {
        return DB::table('visita_registro_comentarios as c')
            ->join('visita_registros as r', 'r.id', '=', 'c.visita_registro_id')
            ->join('visitas as v', 'v.id', '=', 'r.visita_id')
            ->leftJoin('visita_registro_comentario_leituras as l', fn ($j) => $j
                ->on('l.visita_registro_id', '=', 'c.visita_registro_id')
                ->where('l.usuario_id', '=', $usuario->id))
            ->where('v.empresa_id', $usuario->empresa_id)
            ->where('c.usuario_id', '!=', $usuario->id)
            ->where(fn ($q) => $q->whereNull('l.lido_em')->orWhereColumn('c.created_at', '>', 'l.lido_em'))
            ->count();
    }

    /** ATIVIDADES_ALERTA_REQUER_RESOLUCAO (doc 19, decisão 3) — ausente/inativo = false. */
    private function requerResolucao(): bool
    {
        $parametro = Parametro::query()->where('chave', 'ATIVIDADES_ALERTA_REQUER_RESOLUCAO')->first();

        return $parametro !== null
            && $parametro->ativo
            && in_array(strtolower((string) $parametro->valor), self::VALORES_VERDADEIROS, true);
    }

    /**
     * Mesmo shape enxuto do usuário em todo evento do feed — inclui foto_url (mesmo cálculo de
     * UsuarioResource) pra render de avatar no admin.
     *
     * @return array{id: string, nome: string, foto_url: string|null}|null
     */
    private function usuarioParaEvento(?Usuario $usuario): ?array
    {
        if (! $usuario) {
            return null;
        }

        return [
            'id' => $usuario->uuid,
            'nome' => $usuario->nome,
            'foto_url' => $usuario->foto_path ? url("/api/usuarios/{$usuario->uuid}/foto") : null,
        ];
    }
}

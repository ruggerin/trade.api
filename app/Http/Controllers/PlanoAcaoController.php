<?php

namespace App\Http\Controllers;

use App\Enums\AcaoHistoricoPlanoAcao;
use App\Enums\Permissao;
use App\Enums\StatusEtapaPlanoAcao;
use App\Enums\StatusPlanoAcao;
use App\Enums\UserType;
use App\Http\Requests\PlanoAcao\AlterarStatusEtapaRequest;
use App\Http\Requests\PlanoAcao\StoreEtapaPlanoAcaoRequest;
use App\Http\Requests\PlanoAcao\StorePlanoAcaoRequest;
use App\Http\Resources\PlanoAcaoResource;
use App\Models\PlanoAcao;
use App\Models\PlanoAcaoEtapa;
use App\Models\PlanoAcaoHistorico;
use App\Models\PontoVenda;
use App\Models\RedeLoja;
use App\Models\Usuario;
use App\Models\VisitaRegistro;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Planos de Ação — rastreamento de resolução multi-etapa de um alerta de campo, em vez do
 * "Resolver" boolean (que continua existindo pros casos simples, docs/37 §4.8). MVP do §8 fase 1:
 * nasce de um alerta, etapas montadas na mão, histórico append-only, permissões dedicadas
 * (visualizar/criar/movimentar_etapa/concluir/cancelar). Moldes (fase 2), etapa que gera OS
 * (fase 3) e criação livre/notificação/métricas (fase 4) ficam de fora. Ver
 * docs/37-PLANOS-DE-ACAO.md.
 */
class PlanoAcaoController extends Controller
{
    private const RELACOES_DETALHE = [
        'etapas.responsavel', 'etapas.feitaPor',
        'historicos.usuario', 'historicos.etapa',
        'origemRegistro.visita.pontoVenda', 'origemRegistro.visita.usuario',
        'origemRegistro.tipoRegistro', 'origemRegistro.produtoAuditoria',
        'pontoVenda.redeLoja', 'redeLoja',
        'criadoPor', 'concluidoPor', 'canceladoPor',
    ];

    private const LABEL_STATUS_ETAPA = [
        'PENDENTE' => 'Pendente',
        'EM_ANDAMENTO' => 'Em andamento',
        'FEITA' => 'Feita',
        'CANCELADA' => 'Cancelada',
        'BLOQUEADA' => 'Bloqueada',
    ];

    public function index(Request $request): JsonResponse
    {
        $query = PlanoAcao::query()
            ->when($request->filled('status'), function ($q) use ($request) {
                $status = $request->input('status');
                is_array($status) ? $q->whereIn('status', $status) : $q->where('status', $status);
            })
            ->when($request->boolean('atrasados'), fn ($q) => $this->filtrarAtrasados($q))
            // Responsável de alguma etapa ainda em aberto — "o que está na mão de fulano".
            ->when($request->filled('responsavel_uuid'), fn ($q) => $q->whereHas('etapas', fn ($e) => $e
                ->whereNotIn('status', [StatusEtapaPlanoAcao::FEITA, StatusEtapaPlanoAcao::CANCELADA])
                ->whereHas('responsavel', fn ($u) => $u->where('uuid', $request->input('responsavel_uuid')))))
            ->when($request->filled('registro_uuid'), fn ($q) => $q->whereHas(
                'origemRegistro',
                fn ($r) => $r->where('uuid', $request->input('registro_uuid')),
            ))
            ->when($request->filled('origem_tipo'), fn ($q) => $q->where('origem_tipo', $request->input('origem_tipo')))
            ->when($request->filled('ponto_venda_uuid'), fn ($q) => $q->whereHas(
                'pontoVenda',
                fn ($p) => $p->where('uuid', $request->input('ponto_venda_uuid')),
            ))
            // Rede = planos da própria rede + planos de qualquer loja dela.
            ->when($request->filled('rede_loja_uuid'), function ($q) use ($request) {
                $redeId = RedeLoja::where('uuid', $request->input('rede_loja_uuid'))->value('id');
                $q->where(fn ($q) => $q
                    ->where('rede_loja_id', $redeId)
                    ->orWhereHas('pontoVenda', fn ($p) => $p->where('rede_loja_id', $redeId)));
            })
            ->when($request->filled('busca'), fn ($q) => $q->where('titulo', 'ilike', '%'.$request->input('busca').'%'))
            ->with([
                'etapas.responsavel', 'origemRegistro.tipoRegistro', 'origemRegistro.produtoAuditoria',
                'origemRegistro.visita.pontoVenda', 'origemRegistro.visita.usuario',
                'pontoVenda.redeLoja', 'redeLoja', 'criadoPor',
            ])
            // Ativos primeiro (é o que pede ação), depois o mais recente.
            ->orderByRaw("CASE WHEN status IN ('ABERTO', 'EM_ANDAMENTO') THEN 0 ELSE 1 END")
            ->latest();

        $planos = $query->paginate();

        return response()->json([
            'planos_acao' => PlanoAcaoResource::collection($planos->items()),
            'meta' => [
                'current_page' => $planos->currentPage(),
                'last_page' => $planos->lastPage(),
                'per_page' => $planos->perPage(),
                'total' => $planos->total(),
            ],
            'resumo' => $this->resumo(),
        ]);
    }

    public function show(Request $request, PlanoAcao $planoAcao): JsonResponse
    {
        return $this->respostaDetalhe($request, $planoAcao);
    }

    /**
     * Quem pode ser responsável por uma etapa — qualquer usuário ativo da empresa. Rota própria
     * (atrás de planos_acao.visualizar) porque GET /usuarios exige usuarios.gerenciar, que um
     * "Supervisor de Vendas" só com planos_acao.* não tem (docs/37 §6).
     */
    public function responsaveis(): JsonResponse
    {
        $usuarios = Usuario::query()
            ->where('ativo', true)
            ->whereIn('user_type', [UserType::ADMIN, UserType::GESTOR, UserType::PROMOTOR])
            ->orderBy('nome')
            ->get();

        return response()->json([
            'responsaveis' => $usuarios->map(fn (Usuario $u) => [
                'id' => $u->uuid,
                'nome' => $u->nome,
                'user_type' => $u->user_type,
            ]),
        ]);
    }

    /**
     * Dois jeitos de nascer (§4.2): a partir de um alerta (`registro_uuid` — a loja vem do
     * alerta) ou livre, sem alerta, opcionalmente ligado a uma loja OU uma rede.
     */
    public function store(StorePlanoAcaoRequest $request): JsonResponse
    {
        $dados = $request->validated();
        $registro = null;

        if (! empty($dados['registro_uuid'])) {
            // whereHas('visita') aplica o escopo de empresa de Visita — VisitaRegistro não tem
            // empresa_id próprio.
            $registro = VisitaRegistro::query()
                ->where('uuid', $dados['registro_uuid'])
                ->whereNull('cancelado_em')
                ->whereHas('visita')
                ->with(['tipoRegistro', 'produtoAuditoria', 'visita'])
                ->first();

            if (! $registro) {
                throw ValidationException::withMessages(['registro_uuid' => 'Alerta não encontrado.']);
            }
            if (! $registro->tipoRegistro?->eh_alerta) {
                throw ValidationException::withMessages(['registro_uuid' => 'Este registro não é um alerta.']);
            }

            // §5: um alerta pode ter vários planos ao longo do tempo, mas só um ativo por vez —
            // devolve o existente pro front levar o usuário até ele em vez de só dar erro.
            if ($existente = $this->planoAtivoDoAlerta($registro)) {
                return $this->respostaPlanoJaExiste($existente);
            }
        }

        $pontoVenda = ! empty($dados['ponto_venda_uuid']) ? PontoVenda::where('uuid', $dados['ponto_venda_uuid'])->first() : null;
        $redeLoja = ! empty($dados['rede_loja_uuid']) ? RedeLoja::where('uuid', $dados['rede_loja_uuid'])->first() : null;
        $responsaveis = $this->resolverResponsaveis(array_column($dados['etapas'], 'responsavel_uuid'));

        try {
            $plano = DB::transaction(function () use ($dados, $request, $registro, $pontoVenda, $redeLoja, $responsaveis) {
                $plano = PlanoAcao::create([
                    'empresa_id' => $request->user()->empresa_id,
                    'titulo' => $dados['titulo'],
                    'descricao' => $dados['descricao'] ?? null,
                    'origem_tipo' => $registro ? PlanoAcao::ORIGEM_ALERTA : PlanoAcao::ORIGEM_LIVRE,
                    'origem_registro_id' => $registro?->id,
                    'ponto_venda_id' => $registro ? $registro->visita->ponto_venda_id : $pontoVenda?->id,
                    'rede_loja_id' => $redeLoja?->id,
                    'status' => StatusPlanoAcao::ABERTO,
                    'prazo' => $dados['prazo'] ?? null,
                    'criado_por_id' => $request->user()->id,
                ]);

                foreach (array_values($dados['etapas']) as $i => $etapa) {
                    $this->criarEtapa($plano, $etapa, $i + 1, $responsaveis);
                }

                $total = count($dados['etapas']);
                $etapasTexto = sprintf('%d %s', $total, $total === 1 ? 'etapa' : 'etapas');
                $descricao = match (true) {
                    $registro !== null => sprintf(
                        'Plano criado a partir do alerta "%s"%s, com %s',
                        $registro->tipoRegistro->descricao,
                        $registro->produtoAuditoria ? " ({$registro->produtoAuditoria->descricao})" : '',
                        $etapasTexto,
                    ),
                    $pontoVenda !== null => "Plano criado para a loja {$pontoVenda->fantasia}, com {$etapasTexto}",
                    $redeLoja !== null => "Plano criado para a rede {$redeLoja->descricao}, com {$etapasTexto}",
                    default => "Plano criado com {$etapasTexto}",
                };
                $this->registrarHistorico($request, $plano, AcaoHistoricoPlanoAcao::PLANO_CRIADO, $descricao);

                return $plano;
            });
        } catch (UniqueConstraintViolationException $e) {
            // Dois cliques simultâneos no mesmo alerta passaram juntos pela checagem acima — o
            // índice parcial planos_acao_um_ativo_por_alerta segurou o segundo.
            if (! $registro) {
                throw $e;
            }

            return $this->respostaPlanoJaExiste($this->planoAtivoDoAlerta($registro));
        }

        return $this->respostaDetalhe($request, $plano, 201);
    }

    /**
     * "+ Adicionar nova etapa" — o plano não fica preso ao que foi montado na abertura, dá pra
     * encaixar uma etapa ad-hoc durante a execução (sempre no fim da fila).
     */
    public function adicionarEtapa(StoreEtapaPlanoAcaoRequest $request, PlanoAcao $planoAcao): JsonResponse
    {
        $this->exigirAtivo($planoAcao);
        $dados = $request->validated();

        DB::transaction(function () use ($request, $planoAcao, $dados) {
            // Trava a linha do plano (Postgres não aceita FOR UPDATE junto de MAX) — serializa
            // duas adições simultâneas, que senão calculariam a mesma `ordem`.
            PlanoAcao::whereKey($planoAcao->id)->lockForUpdate()->first();
            $ordem = (int) $planoAcao->etapas()->max('ordem') + 1;
            $etapa = $this->criarEtapa($planoAcao, $dados, $ordem, $this->resolverResponsaveis([$dados['responsavel_uuid'] ?? null]));

            $this->registrarHistorico($request, $planoAcao, AcaoHistoricoPlanoAcao::ETAPA_ADICIONADA, "Etapa {$ordem} \"{$etapa->titulo}\" adicionada", etapa: $etapa);
        });

        return $this->respostaDetalhe($request, $planoAcao->refresh());
    }

    public function alterarStatusEtapa(AlterarStatusEtapaRequest $request, PlanoAcao $planoAcao, PlanoAcaoEtapa $etapa): JsonResponse
    {
        abort_if($etapa->plano_acao_id !== $planoAcao->id, 404);
        $this->exigirAtivo($planoAcao);

        $dados = $request->validated();
        $anterior = $etapa->status;
        $novo = StatusEtapaPlanoAcao::from($dados['status']);

        if (! in_array($novo, $anterior->transicoesPermitidas(), true)) {
            throw ValidationException::withMessages(['status' => $anterior->finalizada()
                ? 'Esta etapa já foi finalizada — para corrigir, adicione uma etapa nova.'
                : 'Transição de status não permitida.']);
        }

        $arquivo = $request->file('evidencia_arquivo');
        $texto = isset($dados['evidencia_texto']) ? trim($dados['evidencia_texto']) : null;

        if ($novo === StatusEtapaPlanoAcao::FEITA && $etapa->evidencia_obrigatoria && ! $texto && ! $arquivo) {
            throw ValidationException::withMessages(['evidencia_texto' => 'Esta etapa exige evidência (texto ou anexo) para ser marcada como feita.']);
        }

        DB::transaction(function () use ($request, $planoAcao, $etapa, $anterior, $novo, $dados, $arquivo, $texto) {
            $atualizacao = ['status' => $novo];

            if ($novo === StatusEtapaPlanoAcao::FEITA) {
                $atualizacao += ['feita_em' => now(), 'feita_por_id' => $request->user()->id, 'motivo' => null];
                if ($texto) {
                    $atualizacao['evidencia_texto'] = $texto;
                }
                if ($arquivo) {
                    $nome = "{$etapa->uuid}.".($arquivo->extension() ?: 'bin');
                    $arquivo->storeAs("planos-acao/{$planoAcao->uuid}", $nome, config('filesystems.default'));
                    $atualizacao['evidencia_arquivo_path'] = "planos-acao/{$planoAcao->uuid}/{$nome}";
                }
            } else {
                // Motivo exibido no card é o do status atual — ao desbloquear, some dali (o
                // histórico continua guardando o de antes).
                $atualizacao['motivo'] = $dados['motivo'] ?? null;
            }

            $etapa->update($atualizacao);

            // Plano "começa" sozinho na primeira movimentação de etapa — ninguém inicia na mão.
            if ($planoAcao->status === StatusPlanoAcao::ABERTO) {
                $planoAcao->update(['status' => StatusPlanoAcao::EM_ANDAMENTO]);
            }

            $descricao = sprintf(
                'Etapa %d "%s": %s → %s',
                $etapa->ordem,
                $etapa->titulo,
                self::LABEL_STATUS_ETAPA[$anterior->value],
                self::LABEL_STATUS_ETAPA[$novo->value],
            );
            // Ator externo nunca movimenta nada — registra quem do sistema acompanhou por ele (§6).
            if ($etapa->responsavel_externo_nome) {
                $descricao .= " (acompanhando {$etapa->responsavel_externo_nome})";
            }

            $this->registrarHistorico(
                $request, $planoAcao, AcaoHistoricoPlanoAcao::ETAPA_STATUS_ALTERADO, $descricao,
                etapa: $etapa, anterior: $anterior->value, novo: $novo->value, motivo: $dados['motivo'] ?? null,
            );
        });

        return $this->respostaDetalhe($request, $planoAcao->refresh());
    }

    /**
     * Fechar o plano inteiro — permissão própria (planos_acao.concluir), deliberadamente mais
     * restrita que movimentar etapa (§4.7). Exige todas as etapas finalizadas (FEITA ou
     * CANCELADA). Também marca o alerta de origem como resolvido, se ainda não estava — o
     * Painel de Atividades e a Operação do Dia continuam contando "alerta pendente" pelo
     * boolean de sempre.
     */
    public function concluir(Request $request, PlanoAcao $planoAcao): JsonResponse
    {
        $this->exigirAtivo($planoAcao);

        $pendentes = $planoAcao->etapas()
            ->whereNotIn('status', [StatusEtapaPlanoAcao::FEITA, StatusEtapaPlanoAcao::CANCELADA])
            ->count();
        if ($pendentes > 0) {
            throw ValidationException::withMessages(['status' => $pendentes === 1
                ? 'Ainda há 1 etapa em aberto — finalize ou cancele antes de concluir o plano.'
                : "Ainda há {$pendentes} etapas em aberto — finalize ou cancele antes de concluir o plano."]);
        }

        DB::transaction(function () use ($request, $planoAcao) {
            $planoAcao->update([
                'status' => StatusPlanoAcao::CONCLUIDO,
                'concluido_em' => now(),
                'concluido_por_id' => $request->user()->id,
            ]);

            $registro = $planoAcao->origemRegistro;
            if ($registro && $registro->alerta_resolvido_em === null) {
                $registro->update(['alerta_resolvido_em' => now(), 'alerta_resolvido_por_id' => $request->user()->id]);
            }

            $this->registrarHistorico($request, $planoAcao, AcaoHistoricoPlanoAcao::PLANO_CONCLUIDO, 'Plano concluído');
        });

        return $this->respostaDetalhe($request, $planoAcao->refresh());
    }

    public function cancelar(Request $request, PlanoAcao $planoAcao): JsonResponse
    {
        $dados = $request->validate(['motivo' => ['required', 'string', 'max:2000']]);
        $this->exigirAtivo($planoAcao);

        DB::transaction(function () use ($request, $planoAcao, $dados) {
            $planoAcao->update([
                'status' => StatusPlanoAcao::CANCELADO,
                'cancelado_em' => now(),
                'cancelado_por_id' => $request->user()->id,
                'motivo_cancelamento' => $dados['motivo'],
            ]);

            $this->registrarHistorico($request, $planoAcao, AcaoHistoricoPlanoAcao::PLANO_CANCELADO, 'Plano cancelado', motivo: $dados['motivo']);
        });

        return $this->respostaDetalhe($request, $planoAcao->refresh());
    }

    public function evidencia(PlanoAcao $planoAcao, PlanoAcaoEtapa $etapa): StreamedResponse
    {
        abort_if($etapa->plano_acao_id !== $planoAcao->id || ! $etapa->evidencia_arquivo_path, 404);

        return Storage::disk(config('filesystems.default'))->response($etapa->evidencia_arquivo_path);
    }

    private function respostaDetalhe(Request $request, PlanoAcao $plano, int $status = 200): JsonResponse
    {
        $plano->load(self::RELACOES_DETALHE);
        // URL de evidência precisa do uuid do plano — evita N queries recarregando o pai.
        $plano->etapas->each(fn (PlanoAcaoEtapa $e) => $e->setRelation('planoAcao', $plano));

        $usuario = $request->user();

        return response()->json([
            'plano_acao' => new PlanoAcaoResource($plano),
            // O que o usuário atual pode fazer — o /auth/me não expõe as permissões do perfil, e
            // o botão "Concluir" desabilitado com aviso (§4.7) precisa saber disso de antemão.
            'permissoes' => [
                'movimentar_etapa' => $usuario->temPermissao(Permissao::PLANOS_ACAO_MOVIMENTAR_ETAPA),
                'concluir' => $usuario->temPermissao(Permissao::PLANOS_ACAO_CONCLUIR),
                'cancelar' => $usuario->temPermissao(Permissao::PLANOS_ACAO_CANCELAR),
            ],
        ], $status);
    }

    private function respostaPlanoJaExiste(?PlanoAcao $existente): JsonResponse
    {
        return response()->json([
            'message' => 'Já existe um plano de ação em andamento para este alerta.',
            'plano_acao_id' => $existente?->uuid,
        ], 422);
    }

    private function planoAtivoDoAlerta(VisitaRegistro $registro): ?PlanoAcao
    {
        return PlanoAcao::query()
            ->where('origem_registro_id', $registro->id)
            ->whereIn('status', StatusPlanoAcao::ativos())
            ->first();
    }

    private function exigirAtivo(PlanoAcao $plano): void
    {
        if (! $plano->status->ativo()) {
            throw ValidationException::withMessages(['status' => 'Este plano já foi concluído ou cancelado.']);
        }
    }

    /**
     * @param  array<int, string|null>  $uuids
     * @return array<string, int> uuid => id
     */
    private function resolverResponsaveis(array $uuids): array
    {
        $uuids = array_values(array_filter($uuids));

        return $uuids ? Usuario::whereIn('uuid', $uuids)->pluck('id', 'uuid')->all() : [];
    }

    /** @param  array<string, int>  $responsaveis */
    private function criarEtapa(PlanoAcao $plano, array $dados, int $ordem, array $responsaveis): PlanoAcaoEtapa
    {
        return PlanoAcaoEtapa::create([
            'plano_acao_id' => $plano->id,
            'ordem' => $ordem,
            'titulo' => $dados['titulo'],
            'descricao' => $dados['descricao'] ?? null,
            'prazo' => $dados['prazo'] ?? null,
            'responsavel_id' => isset($dados['responsavel_uuid']) ? ($responsaveis[$dados['responsavel_uuid']] ?? null) : null,
            'responsavel_externo_nome' => $dados['responsavel_externo_nome'] ?? null,
            'responsavel_externo_contato' => $dados['responsavel_externo_contato'] ?? null,
            'evidencia_obrigatoria' => $dados['evidencia_obrigatoria'] ?? false,
        ]);
    }

    private function registrarHistorico(
        Request $request,
        PlanoAcao $plano,
        AcaoHistoricoPlanoAcao $acao,
        string $descricao,
        ?PlanoAcaoEtapa $etapa = null,
        ?string $anterior = null,
        ?string $novo = null,
        ?string $motivo = null,
    ): void {
        PlanoAcaoHistorico::create([
            'plano_acao_id' => $plano->id,
            'etapa_id' => $etapa?->id,
            'usuario_id' => $request->user()->id,
            'acao' => $acao,
            'status_anterior' => $anterior,
            'status_novo' => $novo,
            'motivo' => $motivo,
            'descricao' => mb_substr($descricao, 0, 255),
        ]);
    }

    /**
     * Plano ativo com prazo próprio vencido, ou com alguma etapa em aberto de prazo vencido —
     * mesma regra do campo `atrasado` de PlanoAcaoResource.
     */
    private function filtrarAtrasados(Builder $query): Builder
    {
        return $query
            ->whereIn('status', StatusPlanoAcao::ativos())
            ->where(fn ($q) => $q
                ->whereDate('prazo', '<', today())
                ->orWhereHas('etapas', fn ($e) => $e
                    ->whereNotIn('status', [StatusEtapaPlanoAcao::FEITA, StatusEtapaPlanoAcao::CANCELADA])
                    ->whereDate('prazo', '<', today())));
    }

    /**
     * KPIs do topo da lista (protótipo, docs/37-agentes/37-PROTOTIPO.md) — sempre da empresa
     * inteira, independente dos filtros aplicados na tabela.
     */
    private function resumo(): array
    {
        $inicioMes = now()->startOfMonth();
        $concluidosMes = PlanoAcao::query()
            ->where('status', StatusPlanoAcao::CONCLUIDO)
            ->where('concluido_em', '>=', $inicioMes);

        // Tempo de calendário simples (aberto → concluído), sem pausar por espera de terceiro —
        // decisão da v1, §4.1.
        $mediaSegundos = (clone $concluidosMes)
            ->selectRaw('AVG(EXTRACT(EPOCH FROM (concluido_em - created_at))) as media')
            ->value('media');

        return [
            'ativos' => PlanoAcao::query()->whereIn('status', StatusPlanoAcao::ativos())->count(),
            'atrasados' => $this->filtrarAtrasados(PlanoAcao::query())->count(),
            'concluidos_mes' => $concluidosMes->count(),
            'tempo_medio_resolucao_horas' => $mediaSegundos !== null ? round((float) $mediaSegundos / 3600, 1) : null,
        ];
    }
}

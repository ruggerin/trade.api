<?php

namespace App\Http\Controllers;

use App\Enums\StatusOrdemServico;
use App\Enums\StatusVisita;
use App\Enums\TipoCampoRegistro;
use App\Enums\UserType;
use App\Models\CampoTipoRegistro;
use App\Models\OrdemServico;
use App\Models\PontoVenda;
use App\Models\ProdutoAuditoria;
use App\Models\RedeLoja;
use App\Models\TipoRegistro;
use App\Models\Usuario;
use App\Models\Visita;
use App\Models\VisitaRegistro;
use App\Relatorios\Periodo;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;

/**
 * Relatórios agregados do admin (docs/28-RELATORIOS-FEEDBACK-HISTORICO.md §2) — os primeiros
 * endpoints de agregação do backend. Só ADMIN/GESTOR, mesma regra do Painel de Atividades; o
 * isolamento por empresa vem dos global scopes de Visita/OrdemServico (VisitaRegistro herda via
 * `whereHas('visita')`).
 *
 * Agregação em memória, não em SQL: o recorte é sempre um período curto de uma empresa e o
 * projeto ainda não tem volume que justifique materializar nada. Se um relatório ficar lento,
 * o ponto de troca é a query de cada método, o formato da resposta não muda.
 */
class RelatorioController extends Controller
{
    /** Máximo de dias de um período — protege a agregação em memória de um pedido "desde sempre". */
    private const MAX_DIAS = 92;

    /** Máximo de itens de uma listagem (respostas de texto, top de produtos). */
    private const LIMITE_LISTAGEM = 50;

    /**
     * Planejado (OrdemServico por prazo_fim) × executado (Visita), agrupado por dia + promotor.
     *
     * Categorias, por OS: `cumprida` (CONCLUIDA), `em_andamento`, `atrasada` (ainda em aberto e o
     * prazo já passou) e `a_vencer` (em aberto, prazo ainda no futuro). CANCELADA e
     * AGUARDANDO_APROVACAO não entram no planejado — mas CANCELADA agora é contada à parte, com
     * quebra por responsável e motivo (docs/59), pra cancelar não "limpar" o atraso sem rastro. `espontaneas` é o inverso: visita sem OS, contada à parte pra não
     * inflar o cumprimento.
     */
    public function visitasPlanejadasXExecutadas(Request $request): JsonResponse
    {
        $this->exigirAdminOuGestor($request);
        [$inicio, $fim, $tz] = $this->periodo($request);
        $request->validate(['comparar' => ['nullable', 'in:anterior,ano_anterior']]);

        $usuarioId = $request->filled('usuario_uuid')
            ? Usuario::where('uuid', $request->string('usuario_uuid'))->value('id')
            : null;
        $pontoVendaId = $request->filled('ponto_venda_uuid')
            ? PontoVenda::where('uuid', $request->string('ponto_venda_uuid'))->value('id')
            : null;

        $atual = $this->calcularVisitasPlanejadas($inicio, $fim, $tz, $usuarioId, $pontoVendaId);

        $resposta = [
            'periodo' => $this->periodoLocal($inicio, $fim, $tz),
            'linhas' => $atual['linhas'],
            'total' => $atual['total'],
        ];

        // Comparativo de períodos (docs/59 §4.3): o backend calcula o período equivalente e devolve
        // os dois blocos — o front só mostra a variação, não refaz a conta.
        if ($request->filled('comparar')) {
            [$compInicio, $compFim] = $this->periodoDeComparacao($inicio, $fim, $tz, $request->string('comparar')->toString());
            $resposta['comparativo'] = [
                'tipo' => $request->string('comparar')->toString(),
                'periodo' => $this->periodoLocal($compInicio, $compFim, $tz),
                'total' => $this->calcularVisitasPlanejadas($compInicio, $compFim, $tz, $usuarioId, $pontoVendaId)['total'],
            ];
        }

        return response()->json($resposta);
    }

    /**
     * @return array{linhas: Collection, total: array<string, mixed>}
     */
    private function calcularVisitasPlanejadas(Carbon $inicio, Carbon $fim, string $tz, ?int $usuarioId, ?int $pontoVendaId): array
    {
        $agora = now();

        $ordens = OrdemServico::query()
            ->with(['usuario:id,uuid,nome', 'visita:id,usuario_id', 'visita.usuario:id,uuid,nome', 'motivoCancelamento:id,descricao'])
            ->whereBetween('prazo_fim', [$inicio, $fim])
            ->where('status', '!=', StatusOrdemServico::AGUARDANDO_APROVACAO->value)
            ->when($pontoVendaId, fn ($q) => $q->where('ponto_venda_id', $pontoVendaId))
            ->when($usuarioId, fn ($q) => $q->where(
                fn ($sub) => $sub->where('usuario_id', $usuarioId)
                    ->orWhereHas('visita', fn ($v) => $v->where('usuario_id', $usuarioId)),
            ))
            ->get();

        $espontaneas = Visita::query()
            ->with('usuario:id,uuid,nome')
            ->whereNull('ordem_servico_id')
            ->where('status', '!=', StatusVisita::CANCELADA->value)
            ->whereBetween('inicio_data', [$inicio, $fim])
            ->when($pontoVendaId, fn ($q) => $q->where('ponto_venda_id', $pontoVendaId))
            ->when($usuarioId, fn ($q) => $q->where('usuario_id', $usuarioId))
            ->get();

        $linhas = [];

        $linha = function (Carbon $dia, ?Usuario $promotor) use (&$linhas): void {
            $chave = $dia->toDateString().'|'.($promotor?->uuid ?? '-');
            $linhas[$chave] ??= [
                'data' => $dia->toDateString(),
                'promotor' => $promotor ? ['id' => $promotor->uuid, 'nome' => $promotor->nome] : null,
                'planejadas' => 0, 'cumpridas' => 0, 'em_andamento' => 0, 'atrasadas' => 0, 'a_vencer' => 0,
                'canceladas' => 0, 'canceladas_promotor' => 0,
                'espontaneas' => 0,
            ];
        };

        $porResponsavel = [];
        $porMotivo = [];

        foreach ($ordens as $os) {
            // Quem executou manda; sem visita, vale o promotor a quem a OS foi direcionada (null =
            // fila aberta, ninguém pegou ainda).
            $promotor = $os->visita?->usuario ?? $os->usuario;
            $dia = $os->prazo_fim->copy()->setTimezone($tz)->startOfDay();
            $linha($dia, $promotor);
            $chave = $dia->toDateString().'|'.($promotor?->uuid ?? '-');

            // Cancelada fica fora do "planejado" (nunca valeu) mas AGORA é contada à parte — antes
            // cancelar uma visita vencida limpava o atraso sem deixar rastro (docs/59 §3.4).
            if ($os->status === StatusOrdemServico::CANCELADA) {
                $linhas[$chave]['canceladas']++;
                $responsavel = $os->responsavel_nao_execucao ?? 'NAO_INFORMADO';
                $porResponsavel[$responsavel] = ($porResponsavel[$responsavel] ?? 0) + 1;
                if ($responsavel === 'PROMOTOR') {
                    $linhas[$chave]['canceladas_promotor']++;
                }
                $rotulo = $os->motivoCancelamento?->descricao ?? ($os->motivo_cancelamento_texto ? 'Outro (texto livre)' : 'Sem motivo informado');
                $porMotivo[$rotulo] = ($porMotivo[$rotulo] ?? 0) + 1;

                continue;
            }

            $linhas[$chave]['planejadas']++;
            $categoria = match ($os->status) {
                StatusOrdemServico::CONCLUIDA => 'cumpridas',
                StatusOrdemServico::EM_ANDAMENTO => 'em_andamento',
                default => $os->prazo_fim->lt($agora) ? 'atrasadas' : 'a_vencer',
            };
            $linhas[$chave][$categoria]++;
        }

        foreach ($espontaneas as $visita) {
            $dia = $visita->inicio_data->copy()->setTimezone($tz)->startOfDay();
            $linha($dia, $visita->usuario);
            $linhas[$dia->toDateString().'|'.($visita->usuario?->uuid ?? '-')]['espontaneas']++;
        }

        $linhas = collect($linhas)
            ->map(fn (array $l) => $l + [
                'percentual_cumprimento' => $this->percentual($l['cumpridas'], $l['planejadas']),
                'percentual_cumprimento_ajustado' => $this->percentual($l['cumpridas'], $l['planejadas'] + $l['canceladas_promotor']),
            ])
            ->sortBy([['data', 'desc'], fn ($a, $b) => strcmp($a['promotor']['nome'] ?? '', $b['promotor']['nome'] ?? '')])
            ->values();

        $total = [
            'planejadas' => $linhas->sum('planejadas'),
            'cumpridas' => $linhas->sum('cumpridas'),
            'em_andamento' => $linhas->sum('em_andamento'),
            'atrasadas' => $linhas->sum('atrasadas'),
            'a_vencer' => $linhas->sum('a_vencer'),
            'canceladas' => $linhas->sum('canceladas'),
            'canceladas_promotor' => $linhas->sum('canceladas_promotor'),
            'espontaneas' => $linhas->sum('espontaneas'),
        ];
        $total['percentual_cumprimento'] = $this->percentual($total['cumpridas'], $total['planejadas']);
        // Cancelar por culpa do promotor entra no denominador: faltar e "justificar" não melhora o
        // número dele (docs/59 §3.4).
        $total['percentual_cumprimento_ajustado'] = $this->percentual($total['cumpridas'], $total['planejadas'] + $total['canceladas_promotor']);
        $total['canceladas_por_responsavel'] = $porResponsavel;
        $total['canceladas_por_motivo'] = collect($porMotivo)
            ->map(fn (int $q, string $motivo) => ['motivo' => $motivo, 'quantidade' => $q])
            ->sortByDesc('quantidade')->values()->all();

        return ['linhas' => $linhas, 'total' => $total];
    }

    /** @return array{data_inicio: string, data_fim: string} */
    private function periodoLocal(Carbon $inicio, Carbon $fim, string $tz): array
    {
        return ['data_inicio' => $inicio->copy()->setTimezone($tz)->toDateString(), 'data_fim' => $fim->copy()->setTimezone($tz)->toDateString()];
    }

    /**
     * Período equivalente pra comparar: `anterior` = mesmo número de dias imediatamente antes;
     * `ano_anterior` = mesmas datas um ano antes. Devolvido em UTC, como `periodo()`.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private function periodoDeComparacao(Carbon $inicio, Carbon $fim, string $tz, string $tipo): array
    {
        // Regra única com o gerador de relatórios (docs/60).
        return Periodo::comparacao($inicio, $fim, $tz, $tipo);
    }

    /**
     * Respostas de um formulário agregadas por pergunta. Sem `campo_chave` devolve todos os campos
     * do tipo; com ele, só aquele. Agregação por tipo de campo:
     * BOOLEANO/MULTIPLA_ESCOLHA → contagem por valor; NUMERO/MOEDA → soma/média/mín/máx;
     * SORTIMENTO (Mix) → produtos mais ausentes; TEXTO/DATA → listagem crua das últimas respostas.
     * Ruptura (flag própria do registro) vem em `rupturas` quando o tipo é de ruptura.
     */
    public function respostasFormulario(Request $request): JsonResponse
    {
        $this->exigirAdminOuGestor($request);
        $request->validate(['tipo_registro_uuid' => ['required', 'string']]);
        [$inicio, $fim, $tz] = $this->periodo($request);

        $tipo = $this->tipoRegistro($request);
        $registros = $this->registrosDoFormulario($request, $tipo, $inicio, $fim)
            ->with('produtoAuditoria:id,uuid,descricao')
            ->orderByDesc('id')
            ->get();

        $campos = $tipo->campos
            ->when($request->filled('campo_chave'), fn (Collection $c) => $c->where('chave', $request->string('campo_chave')->toString()))
            ->values();

        $resposta = [
            'tipo_registro' => ['id' => $tipo->uuid, 'descricao' => $tipo->descricao],
            'periodo' => ['data_inicio' => $inicio->copy()->setTimezone($tz)->toDateString(), 'data_fim' => $fim->copy()->setTimezone($tz)->toDateString()],
            'total_registros' => $registros->count(),
            'campos' => $campos->map(fn (CampoTipoRegistro $campo) => $this->agregarCampo($campo, $registros))->all(),
        ];

        if ($tipo->eh_ruptura) {
            $resposta['rupturas'] = $this->agregarRupturas($registros);
        }

        return response()->json($resposta);
    }

    /**
     * "Coleta por Formulário" (docs/39-RELATORIO-ANALITICO-PIVOT.md) — a aba ao lado do
     * sintético. Devolve uma lista PLANA de células (registro × pergunta respondida); quem decide
     * o que é linha e o que é coluna é o front, que monta a matriz (§4). Mesmos filtros do
     * sintético. Campo SORTIMENTO fica de fora: é um checklist inteiro, não cabe numa célula.
     */
    public function respostasFormularioAnalitico(Request $request): JsonResponse
    {
        $this->exigirAdminOuGestor($request);
        $request->validate(['tipo_registro_uuid' => ['required', 'string']]);
        [$inicio, $fim, $tz] = $this->periodo($request);

        $tipo = $this->tipoRegistro($request);
        $campos = $tipo->campos
            ->filter(fn (CampoTipoRegistro $c) => $c->tipo_campo !== TipoCampoRegistro::SORTIMENTO)
            ->when($request->filled('campo_chave'), fn (Collection $c) => $c->where('chave', $request->string('campo_chave')->toString()))
            ->values();

        $registros = $this->registrosDoFormulario($request, $tipo, $inicio, $fim)
            ->with([
                'produtoAuditoria:id,uuid,descricao,codigo_externo',
                'visita:id,uuid,ponto_venda_id,usuario_id,inicio_data',
                'visita.pontoVenda:id,uuid,fantasia,rede_loja_id',
                'visita.pontoVenda.redeLoja:id,descricao',
                'visita.usuario:id,uuid,nome',
            ])
            ->orderBy('id')
            ->get();

        $celulas = [];
        foreach ($registros as $registro) {
            $visita = $registro->visita;
            foreach ($campos as $campo) {
                $bruto = $registro->valores_campos[$campo->chave] ?? null;
                if ($bruto === null || $bruto === '') {
                    continue;
                }

                $numerico = in_array($campo->tipo_campo, [TipoCampoRegistro::NUMERO, TipoCampoRegistro::MOEDA], true);
                $celulas[] = [
                    'registro_id' => $registro->uuid,
                    'visita_id' => $visita?->uuid,
                    'registrado_em' => $visita?->inicio_data ?? $registro->created_at,
                    'ponto_venda' => $visita?->pontoVenda ? [
                        'id' => $visita->pontoVenda->uuid,
                        'fantasia' => $visita->pontoVenda->fantasia,
                        'rede' => $visita->pontoVenda->redeLoja?->descricao,
                    ] : null,
                    'promotor' => $visita?->usuario?->nome,
                    'produto' => $registro->produtoAuditoria ? [
                        'id' => $registro->produtoAuditoria->uuid,
                        'descricao' => $registro->produtoAuditoria->descricao,
                        'codigo_externo' => $registro->produtoAuditoria->codigo_externo,
                    ] : null,
                    'campo' => $campo->chave,
                    'valor' => $campo->tipo_campo === TipoCampoRegistro::BOOLEANO
                        ? ((string) $bruto === '1' ? 'Sim' : 'Não')
                        : (string) $bruto,
                    // Já parseado ("12,50" e "12.50" — o campo é texto livre no mobile) pro front
                    // não repetir a regra de estatisticas(); null quando não é número de verdade.
                    'valor_numerico' => $numerico ? $this->numero($bruto) : null,
                ];
            }
        }

        return response()->json([
            'tipo_registro' => ['id' => $tipo->uuid, 'descricao' => $tipo->descricao],
            'periodo' => ['data_inicio' => $inicio->copy()->setTimezone($tz)->toDateString(), 'data_fim' => $fim->copy()->setTimezone($tz)->toDateString()],
            'total_registros' => $registros->count(),
            // Ordem do formulário — o front usa pra ordenar as colunas de pergunta.
            'campos' => $campos->map(fn (CampoTipoRegistro $c) => [
                'chave' => $c->chave,
                'rotulo' => $c->rotulo,
                'tipo_campo' => $c->tipo_campo->value,
            ])->all(),
            'celulas' => $celulas,
        ]);
    }

    /**
     * PDF da matriz exatamente como está na tela — o front já montou o pivot (orientação,
     * agrupamento, rodapé), aqui só desenha a tabela. Evita duplicar a lógica do pivot em PHP.
     * POST porque a matriz pode ser grande demais pra query string.
     */
    public function respostasFormularioAnaliticoPdf(Request $request): Response
    {
        $this->exigirAdminOuGestor($request);
        $dados = $request->validate([
            'titulo' => ['required', 'string', 'max:255'],
            'subtitulo' => ['nullable', 'string', 'max:500'],
            'cabecalho' => ['required', 'array', 'min:1', 'max:3'],
            'cabecalho.*' => ['array', 'max:2000'],
            'cabecalho.*.*.texto' => ['nullable', 'string', 'max:255'],
            'cabecalho.*.*.colunas' => ['nullable', 'integer', 'min:1', 'max:2000'],
            'cabecalho.*.*.linhas' => ['nullable', 'integer', 'min:1', 'max:3'],
            'linhas' => ['present', 'array', 'max:5000'],
            'linhas.*' => ['array', 'max:2000'],
            'linhas.*.*' => ['nullable', 'string', 'max:255'],
            'rodape' => ['nullable', 'array', 'max:10'],
            'rodape.*' => ['array', 'max:2000'],
            'rodape.*.*' => ['nullable', 'string', 'max:255'],
        ]);

        $pdf = Pdf::loadView('pdf.relatorio-coleta-formulario', ['dados' => $dados, 'geradoEm' => now()])
            ->setPaper('a4', 'landscape');

        return new Response($pdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.str($dados['titulo'])->slug().'.pdf"',
        ]);
    }

    /** Mesmo relatório de visitasPlanejadasXExecutadas, em PDF (tabela, pra imprimir/enviar). */
    public function visitasPlanejadasXExecutadasPdf(Request $request): Response
    {
        $dados = $this->visitasPlanejadasXExecutadas($request)->getData(true);

        return $this->pdf('pdf.relatorio-visitas-planejadas', 'cumprimento-visitas', $dados, $request);
    }

    /** Mesmo relatório de respostasFormulario, em PDF. */
    public function respostasFormularioPdf(Request $request): Response
    {
        $dados = $this->respostasFormulario($request)->getData(true);

        return $this->pdf('pdf.relatorio-respostas-formulario', 'respostas-'.str($dados['tipo_registro']['descricao'])->slug(), $dados, $request);
    }

    private function pdf(string $view, string $nomeBase, array $dados, Request $request): Response
    {
        $filtros = collect([
            $request->filled('usuario_uuid') ? 'Promotor: '.Usuario::where('uuid', $request->string('usuario_uuid'))->value('nome') : null,
            $request->filled('ponto_venda_uuid') ? 'Loja: '.PontoVenda::where('uuid', $request->string('ponto_venda_uuid'))->value('fantasia') : null,
            $request->filled('rede_loja_uuid') ? 'Rede: '.RedeLoja::where('uuid', $request->string('rede_loja_uuid'))->value('descricao') : null,
        ])->filter()->implode(' | ');

        $pdf = Pdf::loadView($view, ['dados' => $dados, 'filtros' => $filtros, 'geradoEm' => now()]);

        return new Response($pdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "attachment; filename=\"{$nomeBase}.pdf\"",
        ]);
    }

    private function agregarCampo(CampoTipoRegistro $campo, Collection $registros): array
    {
        $valores = $registros
            ->map(fn (VisitaRegistro $r) => $r->valores_campos[$campo->chave] ?? null)
            ->filter(fn ($v) => $v !== null && $v !== '')
            ->values();

        $base = [
            'chave' => $campo->chave,
            'rotulo' => $campo->rotulo,
            'tipo_campo' => $campo->tipo_campo->value,
            'respostas' => $valores->count(),
        ];

        return $base + match ($campo->tipo_campo) {
            TipoCampoRegistro::BOOLEANO => ['contagem' => $this->contagem($valores->map(fn ($v) => $v === '1' ? 'Sim' : 'Não'))],
            TipoCampoRegistro::MULTIPLA_ESCOLHA => ['contagem' => $this->contagem($valores)],
            TipoCampoRegistro::NUMERO, TipoCampoRegistro::MOEDA => ['estatisticas' => $this->estatisticas($valores)],
            TipoCampoRegistro::SORTIMENTO => ['ausencias' => $this->ausenciasMix($valores)],
            default => ['ultimas' => $valores->take(self::LIMITE_LISTAGEM)->values()->all()],
        };
    }

    /** @return array<int, array{valor: string, quantidade: int}> */
    private function contagem(Collection $valores): array
    {
        return $valores
            ->countBy()
            ->map(fn (int $quantidade, string $valor) => ['valor' => $valor, 'quantidade' => $quantidade])
            ->sortByDesc('quantidade')
            ->values()
            ->all();
    }

    /** Aceita "12,50" e "12.50" — o campo é texto livre no mobile. */
    private function numero(mixed $valor): ?float
    {
        $n = str_replace(',', '.', trim((string) $valor));

        return is_numeric($n) ? (float) $n : null;
    }

    private function tipoRegistro(Request $request): TipoRegistro
    {
        $tipo = TipoRegistro::with('campos')->where('uuid', $request->string('tipo_registro_uuid'))->first();
        if (! $tipo) {
            abort(404, 'Formulário não encontrado.');
        }

        return $tipo;
    }

    /** Registros não cancelados do formulário no período — base do sintético e da coleta. */
    private function registrosDoFormulario(Request $request, TipoRegistro $tipo, Carbon $inicio, Carbon $fim): Builder
    {
        $usuarioId = $request->filled('usuario_uuid')
            ? Usuario::where('uuid', $request->string('usuario_uuid'))->value('id')
            : null;
        $pontoVendaId = $request->filled('ponto_venda_uuid')
            ? PontoVenda::where('uuid', $request->string('ponto_venda_uuid'))->value('id')
            : null;
        $redeId = $request->filled('rede_loja_uuid')
            ? RedeLoja::where('uuid', $request->string('rede_loja_uuid'))->value('id')
            : null;

        return VisitaRegistro::query()
            ->where('tipo_registro_id', $tipo->id)
            ->whereNull('cancelado_em')
            ->whereHas('visita', function ($q) use ($inicio, $fim, $usuarioId, $pontoVendaId, $redeId) {
                $q->whereBetween('inicio_data', [$inicio, $fim])
                    ->when($usuarioId, fn ($v) => $v->where('usuario_id', $usuarioId))
                    ->when($pontoVendaId, fn ($v) => $v->where('ponto_venda_id', $pontoVendaId))
                    ->when($redeId, fn ($v) => $v->whereHas('pontoVenda', fn ($p) => $p->where('rede_loja_id', $redeId)));
            });
    }

    /** Aceita "12,50" e "12.50" — o campo é texto livre no mobile. */
    private function estatisticas(Collection $valores): ?array
    {
        $numeros = $valores
            ->map(fn ($v) => $this->numero($v))
            ->filter(fn ($n) => $n !== null)
            ->values();

        if ($numeros->isEmpty()) {
            return null;
        }

        return [
            'soma' => round($numeros->sum(), 2),
            'media' => round($numeros->avg(), 2),
            'minimo' => $numeros->min(),
            'maximo' => $numeros->max(),
        ];
    }

    /** Produtos mais marcados como ausentes num campo Mix, com quantas vezes de quantos checklists. */
    private function ausenciasMix(Collection $valores): array
    {
        $checklists = $valores
            ->map(fn ($v) => is_string($v) ? json_decode($v, true) : null)
            ->filter(fn ($v) => is_array($v));

        $contagem = $checklists->flatMap(fn (array $v) => $v['ausentes'] ?? [])->countBy()->sortDesc()->take(self::LIMITE_LISTAGEM);
        $descricoes = ProdutoAuditoria::whereIn('uuid', $contagem->keys())->pluck('descricao', 'uuid');

        return [
            'checklists' => $checklists->count(),
            'produtos' => $contagem
                ->map(fn (int $vezes, string $uuid) => ['produto' => $descricoes[$uuid] ?? $uuid, 'vezes_ausente' => $vezes])
                ->values()
                ->all(),
        ];
    }

    private function agregarRupturas(Collection $registros): array
    {
        $rupturas = $registros->where('ruptura', true);

        return [
            'total' => $rupturas->count(),
            'por_produto' => $rupturas
                ->groupBy(fn (VisitaRegistro $r) => $r->produtoAuditoria?->descricao ?? 'Sem produto')
                ->map(fn (Collection $g, string $produto) => ['produto' => $produto, 'quantidade' => $g->count()])
                ->sortByDesc('quantidade')
                ->take(self::LIMITE_LISTAGEM)
                ->values()
                ->all(),
        ];
    }

    /** Visita com menos que isso (check-in e saída sem estar na loja) ou mais que isso (checkout esquecido) não vira média. */
    private const TEMPO_MIN_VALIDO = 2;

    private const TEMPO_MAX_VALIDO = 720;

    /**
     * Tempo dentro do PDV (docs/59 §4.2): duração da visita (check-in → checkout) menos o tempo
     * fora da loja já calculado pelo afastamento (docs/49) — a mesma conta do KPI "Tempo em loja"
     * da Rota do Dia, pros números baterem. Agrupa por loja, promotor, rede ou dia; visita sem
     * checkout, cancelada ou fora da faixa válida fica de fora da média e vira `desconsideradas`.
     */
    public function tempoNaLoja(Request $request): JsonResponse
    {
        $this->exigirAdminOuGestor($request);
        [$inicio, $fim, $tz] = $this->periodo($request);
        $request->validate([
            'agrupar' => ['nullable', 'in:loja,promotor,rede,dia'],
            'comparar' => ['nullable', 'in:anterior,ano_anterior'],
        ]);
        $agrupar = $request->input('agrupar', 'loja');

        $filtros = [
            'usuario_id' => $request->filled('usuario_uuid') ? Usuario::where('uuid', $request->string('usuario_uuid'))->value('id') : null,
            'ponto_venda_id' => $request->filled('ponto_venda_uuid') ? PontoVenda::where('uuid', $request->string('ponto_venda_uuid'))->value('id') : null,
            'rede_loja_id' => $request->filled('rede_loja_uuid') ? RedeLoja::where('uuid', $request->string('rede_loja_uuid'))->value('id') : null,
        ];

        $atual = $this->calcularTempoNaLoja($inicio, $fim, $tz, $agrupar, $filtros);
        $resposta = ['periodo' => $this->periodoLocal($inicio, $fim, $tz), 'agrupar' => $agrupar] + $atual;

        if ($request->filled('comparar')) {
            [$compInicio, $compFim] = $this->periodoDeComparacao($inicio, $fim, $tz, $request->string('comparar')->toString());
            $comp = $this->calcularTempoNaLoja($compInicio, $compFim, $tz, $agrupar, $filtros);
            $porChave = $comp['linhas']->keyBy('chave');

            $resposta['linhas'] = $atual['linhas']->map(fn (array $l) => $l + [
                'media_minutos_comparativo' => $porChave->get($l['chave'])['media_minutos'] ?? null,
            ])->values();
            $resposta['comparativo'] = [
                'tipo' => $request->string('comparar')->toString(),
                'periodo' => $this->periodoLocal($compInicio, $compFim, $tz),
                'total' => $comp['total'],
            ];
        }

        return response()->json($resposta);
    }

    /**
     * @param  array{usuario_id: ?int, ponto_venda_id: ?int, rede_loja_id: ?int}  $filtros
     * @return array{linhas: Collection, total: array<string, mixed>}
     */
    private function calcularTempoNaLoja(Carbon $inicio, Carbon $fim, string $tz, string $agrupar, array $filtros): array
    {
        $visitas = Visita::query()
            ->with(['pontoVenda:id,uuid,fantasia,rede_loja_id', 'pontoVenda.redeLoja:id,uuid,descricao', 'usuario:id,uuid,nome'])
            ->where('status', '!=', StatusVisita::CANCELADA->value)
            ->whereNotNull('fim_data')
            ->whereBetween('inicio_data', [$inicio, $fim])
            ->when($filtros['usuario_id'], fn ($q, $id) => $q->where('usuario_id', $id))
            ->when($filtros['ponto_venda_id'], fn ($q, $id) => $q->where('ponto_venda_id', $id))
            ->when($filtros['rede_loja_id'], fn ($q, $id) => $q->whereHas('pontoVenda', fn ($p) => $p->where('rede_loja_id', $id)))
            ->get();

        // Itens trabalhados (docs/61 §6.1): produtos distintos registrados em cada visita.
        $itensPorVisita = VisitaRegistro::query()
            ->whereIn('visita_id', $visitas->pluck('id'))
            ->whereNotNull('produto_auditoria_id')
            ->whereNull('cancelado_em')
            ->selectRaw('visita_id, count(distinct produto_auditoria_id) as itens')
            ->groupBy('visita_id')
            ->pluck('itens', 'visita_id');

        $validas = collect();
        $desconsideradas = 0;
        foreach ($visitas as $visita) {
            $duracao = (int) round($visita->inicio_data->diffInSeconds($visita->fim_data) / 60);
            if ($duracao < self::TEMPO_MIN_VALIDO || $duracao > self::TEMPO_MAX_VALIDO) {
                $desconsideradas++;

                continue;
            }
            $validas->push([
                'visita' => $visita,
                'minutos' => max(0, $duracao - (int) ($visita->afastamento_minutos ?? 0)),
                'itens' => (int) ($itensPorVisita[$visita->id] ?? 0),
            ]);
        }

        $chaveDe = function (array $v) use ($agrupar, $tz): array {
            $visita = $v['visita'];

            return match ($agrupar) {
                'promotor' => [$visita->usuario?->uuid ?? '-', $visita->usuario?->nome ?? 'Sem promotor'],
                'rede' => [$visita->pontoVenda?->redeLoja?->uuid ?? '-', $visita->pontoVenda?->redeLoja?->descricao ?? 'Sem rede'],
                'dia' => [$visita->inicio_data->copy()->setTimezone($tz)->toDateString(), $visita->inicio_data->copy()->setTimezone($tz)->toDateString()],
                default => [$visita->pontoVenda?->uuid ?? '-', $visita->pontoVenda?->fantasia ?? 'Sem loja'],
            };
        };

        $resumo = function (Collection $itens): array {
            $tempo = (int) $itens->sum('minutos');
            $trabalhados = (int) $itens->sum('itens');

            return [
                'visitas' => $itens->count(),
                'tempo_total_minutos' => $tempo,
                'media_minutos' => $itens->isEmpty() ? null : (int) round($itens->avg('minutos')),
                'mediana_minutos' => $this->mediana($itens->pluck('minutos')),
                // Soma dos produtos distintos de cada visita; sem registro por produto fica nulo ("sem informação"), nunca 0.
                'itens_trabalhados' => $trabalhados > 0 ? $trabalhados : null,
                'minutos_por_item' => $trabalhados > 0 ? round($tempo / $trabalhados, 1) : null,
            ];
        };

        $linhas = $validas
            ->groupBy(fn (array $v) => $chaveDe($v)[0])
            ->map(fn (Collection $g, string $chave) => ['chave' => $chave, 'nome' => $chaveDe($g->first())[1]] + $resumo($g))
            ->sortByDesc('tempo_total_minutos')
            ->values();

        return [
            'linhas' => $linhas,
            'total' => $resumo($validas) + ['desconsideradas' => $desconsideradas],
        ];
    }

    private function mediana(Collection $valores): ?int
    {
        if ($valores->isEmpty()) {
            return null;
        }
        $ordenados = $valores->sort()->values();
        $meio = intdiv($ordenados->count(), 2);

        return (int) round($ordenados->count() % 2 ? $ordenados[$meio] : ($ordenados[$meio - 1] + $ordenados[$meio]) / 2);
    }

    private function percentual(int $parte, int $total): ?int
    {
        return $total > 0 ? (int) round($parte / $total * 100) : null;
    }

    /**
     * Período em horário LOCAL de quem consulta (`tz`, IANA — o admin manda o fuso do navegador):
     * "hoje" pro gestor de São Paulo termina às 23:59 dele, não às 21:00 (UTC do servidor). Sem
     * `tz` vale o fuso da aplicação. Devolve início/fim já convertidos pro fuso da aplicação, que
     * é o que o banco guarda e o que os bindings de query esperam, mais o `tz` pra agrupar por dia.
     *
     * @return array{0: Carbon, 1: Carbon, 2: string}
     */
    private function periodo(Request $request): array
    {
        $request->validate([
            'data_inicio' => ['nullable', 'date'],
            'data_fim' => ['nullable', 'date', 'after_or_equal:data_inicio'],
            'tz' => ['nullable', 'timezone:all'],
        ]);

        $tz = $request->input('tz') ?: config('app.timezone');

        $fim = $request->filled('data_fim') ? Carbon::parse($request->string('data_fim'), $tz)->endOfDay() : now($tz)->endOfDay();
        $inicio = $request->filled('data_inicio') ? Carbon::parse($request->string('data_inicio'), $tz)->startOfDay() : $fim->copy()->subDays(6)->startOfDay();

        if ($inicio->diffInDays($fim) > self::MAX_DIAS) {
            abort(422, 'O período máximo de um relatório é de '.self::MAX_DIAS.' dias.');
        }

        // Instante no banco é sempre UTC (docs/50 §2) — converter pro fuso do servidor, como era
        // antes, quebraria no dia em que o servidor não estivesse em UTC.
        return [$inicio->utc(), $fim->utc(), $tz];
    }

    private function exigirAdminOuGestor(Request $request): void
    {
        if (! in_array($request->user()->user_type, [UserType::ADMIN, UserType::GESTOR], true)) {
            abort(403, 'Os relatórios são só para ADMIN/GESTOR.');
        }
    }
}

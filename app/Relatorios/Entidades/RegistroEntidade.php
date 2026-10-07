<?php

namespace App\Relatorios\Entidades;

use App\Enums\TipoCampoRegistro;
use App\Models\CampoTipoRegistro;
use App\Models\MarcaAuditoria;
use App\Models\MotivoResolucaoAlerta;
use App\Models\PontoVenda;
use App\Models\ProdutoAuditoria;
use App\Models\RedeLoja;
use App\Models\TipoRegistro;
use App\Models\Usuario;
use App\Models\VisitaRegistro;
use App\Relatorios\Campo;
use App\Relatorios\Entidade;
use App\Relatorios\Metrica;
use App\Relatorios\Periodo;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Registros das visitas (docs/60 §3.2, Fase 7): formulários respondidos, rupturas e alertas. Com um
 * formulário escolhido (`definicao.formulario`), as perguntas dele viram campos — sim/não e
 * múltipla escolha agrupam e filtram — e métricas — número e moeda somam, tiram média, mínimo e
 * máximo. Registro cancelado nunca entra.
 *
 * VisitaRegistro não tem BelongsToEmpresa: o isolamento vem do `whereHas('visita')`, que passa
 * pelo global scope de Visita (mesmo critério de RelatorioController::registrosDoFormulario).
 */
final class RegistroEntidade extends Entidade
{
    private const GRUPO_PERGUNTAS = 'Perguntas do formulário';

    private const SITUACAO_ALERTA = ['aberto' => 'Em aberto', 'resolvido' => 'Resolvido'];

    public function __construct(private readonly ?TipoRegistro $formulario = null) {}

    public function chave(): string
    {
        return 'registro';
    }

    public function rotulo(): string
    {
        return 'Registros e formulários';
    }

    public function consulta(string $campoPeriodo, Carbon $inicio, Carbon $fim): Builder
    {
        return VisitaRegistro::query()
            ->with([
                'tipoRegistro:id,uuid,descricao,eh_alerta,eh_ruptura',
                'produtoAuditoria:id,uuid,descricao,marca_id',
                'produtoAuditoria.marca:id,uuid,descricao',
                'marca:id,uuid,descricao',
                'motivoResolucao:id,uuid,descricao',
                'visita:id,usuario_id,ponto_venda_id',
                'visita.usuario:id,uuid,nome',
                'visita.pontoVenda:id,uuid,fantasia,rede_loja_id',
                'visita.pontoVenda.redeLoja:id,uuid,descricao',
            ])
            ->whereNull('cancelado_em')
            ->whereHas('visita')
            ->when($this->formulario, fn (Builder $q, TipoRegistro $f) => $q->where('tipo_registro_id', $f->id))
            // Único campo de período: o momento do registro.
            ->whereBetween('visita_registros.created_at', [$inicio, $fim]);
    }

    public function campos(): array
    {
        $daVisita = fn (string $coluna, string $modelo) => function (Builder $q, string $operador, mixed $valor) use ($coluna, $modelo): void {
            $naLista = fn (Builder $v) => $v->whereIn($coluna, $modelo::query()->whereIn('uuid', (array) $valor)->select('id'));
            $operador === 'nao_em' ? $q->whereDoesntHave('visita', $naLista) : $q->whereHas('visita', $naLista);
        };

        $campos = [
            new Campo('loja', 'Loja', Campo::RELACAO, ['em', 'nao_em'], fonte: 'pontos_venda',
                filtrar: $daVisita('ponto_venda_id', PontoVenda::class),
                agrupar: fn (VisitaRegistro $r) => self::grupoDe($r->visita?->pontoVenda, 'fantasia', 'Sem loja')),
            new Campo('rede', 'Rede', Campo::RELACAO, ['em', 'nao_em'], fonte: 'redes_lojas',
                filtrar: function (Builder $q, string $operador, mixed $valor): void {
                    $naRede = fn (Builder $v) => $v->whereHas('pontoVenda', fn (Builder $p) => $p->whereIn('rede_loja_id', RedeLoja::query()->whereIn('uuid', (array) $valor)->select('id')));
                    $operador === 'nao_em' ? $q->whereDoesntHave('visita', $naRede) : $q->whereHas('visita', $naRede);
                },
                agrupar: fn (VisitaRegistro $r) => self::grupoDe($r->visita?->pontoVenda?->redeLoja, 'descricao', 'Sem rede')),
            new Campo('promotor', 'Promotor', Campo::RELACAO, ['em', 'nao_em'], fonte: 'usuarios',
                filtrar: $daVisita('usuario_id', Usuario::class),
                agrupar: fn (VisitaRegistro $r) => self::grupoDe($r->visita?->usuario, 'nome', 'Sem promotor')),
            new Campo('produto', 'Produto', Campo::RELACAO, ['em', 'nao_em', 'vazio', 'nao_vazio'], fonte: 'produtos',
                filtrar: self::filtroRelacao('produto_auditoria_id', ProdutoAuditoria::class),
                agrupar: fn (VisitaRegistro $r) => self::grupoDe($r->produtoAuditoria, 'descricao', 'Sem produto')),
            // A marca do produto; registro feito direto na marca (sem produto) usa a dele.
            new Campo('marca', 'Marca', Campo::RELACAO, ['em', 'nao_em'], fonte: 'marcas',
                filtrar: function (Builder $q, string $operador, mixed $valor): void {
                    $ids = fn () => MarcaAuditoria::query()->whereIn('uuid', (array) $valor)->select('id');
                    $daMarca = fn (Builder $s) => $s->whereIn('marca_id', $ids())
                        ->orWhereHas('produtoAuditoria', fn (Builder $p) => $p->whereIn('marca_id', $ids()));
                    $operador === 'nao_em' ? $q->whereNot($daMarca) : $q->where($daMarca);
                },
                agrupar: fn (VisitaRegistro $r) => self::grupoDe($r->produtoAuditoria?->marca ?? $r->marca, 'descricao', 'Sem marca')),
            new Campo('ruptura', 'Ruptura', Campo::BOOLEANO, ['igual'],
                filtrar: fn (Builder $q, string $operador, mixed $valor) => $q->where('ruptura', filter_var($valor, FILTER_VALIDATE_BOOLEAN)),
                agrupar: fn (VisitaRegistro $r) => $r->ruptura ? ['sim', 'Com ruptura'] : ['nao', 'Sem ruptura']),
            new Campo('alerta', 'É alerta', Campo::BOOLEANO, ['igual'],
                filtrar: fn (Builder $q, string $operador, mixed $valor) => $q->whereHas('tipoRegistro', fn (Builder $t) => $t->where('eh_alerta', filter_var($valor, FILTER_VALIDATE_BOOLEAN))),
                agrupar: fn (VisitaRegistro $r) => self::ehAlerta($r) ? ['sim', 'Alerta'] : ['nao', 'Não é alerta']),
            new Campo('situacao_alerta', 'Situação do alerta', Campo::ENUM, ['em', 'nao_em'], opcoes: self::SITUACAO_ALERTA,
                filtrar: function (Builder $q, string $operador, mixed $valor): void {
                    $casa = function (Builder $s) use ($valor): void {
                        $s->whereHas('tipoRegistro', fn (Builder $t) => $t->where('eh_alerta', true))
                            ->where(function (Builder $w) use ($valor): void {
                                if (in_array('aberto', (array) $valor, true)) {
                                    $w->orWhereNull('alerta_resolvido_em');
                                }
                                if (in_array('resolvido', (array) $valor, true)) {
                                    $w->orWhereNotNull('alerta_resolvido_em');
                                }
                            });
                    };
                    $operador === 'nao_em' ? $q->whereNot($casa) : $q->where($casa);
                },
                agrupar: function (VisitaRegistro $r) {
                    if (! self::ehAlerta($r)) {
                        return ['-', 'Não é alerta'];
                    }

                    return $r->alerta_resolvido_em ? ['resolvido', 'Resolvido'] : ['aberto', 'Em aberto'];
                }),
            new Campo('motivo_resolucao', 'Motivo da resolução', Campo::RELACAO, ['em', 'nao_em', 'vazio', 'nao_vazio'], fonte: 'motivos_resolucao',
                filtrar: self::filtroRelacao('alerta_motivo_id', MotivoResolucaoAlerta::class),
                agrupar: fn (VisitaRegistro $r) => self::grupoDe($r->motivoResolucao, 'descricao', 'Sem motivo')),
            new Campo('registrado_em', 'Data do registro', Campo::DATA, periodo: true,
                agrupar: fn (VisitaRegistro $r, ?string $granularidade, string $tz) => Periodo::agrupar($r->created_at, $granularidade, $tz)),
        ];

        // Com formulário escolhido, filtrar por formulário não faz sentido (já está fixo).
        if (! $this->formulario) {
            array_unshift($campos, new Campo('formulario', 'Formulário', Campo::RELACAO, ['em', 'nao_em'], fonte: 'formularios',
                filtrar: self::filtroRelacao('tipo_registro_id', TipoRegistro::class),
                agrupar: fn (VisitaRegistro $r) => self::grupoDe($r->tipoRegistro, 'descricao', 'Sem formulário')));
        }

        foreach ($this->perguntas() as $pergunta) {
            $campo = self::campoDaPergunta($pergunta);
            if ($campo) {
                $campos[] = $campo;
            }
        }

        return collect($campos)->keyBy(fn (Campo $c) => $c->chave)->all();
    }

    protected function metricasProprias(): array
    {
        $alertas = fn (Collection $i) => $i->filter(fn (VisitaRegistro $r) => self::ehAlerta($r));
        $rupturas = fn (Collection $i) => $i->filter(fn (VisitaRegistro $r) => (bool) $r->ruptura)->count();

        $metricas = [
            new Metrica('registros', 'Registros', Metrica::INTEIRO, fn (Collection $i) => $i->count()),
            new Metrica('rupturas', 'Rupturas', Metrica::INTEIRO, $rupturas),
            new Metrica('percentual_ruptura', '% de ruptura', Metrica::PERCENTUAL,
                fn (Collection $i) => self::percentual($rupturas($i), $i->count()), 'Registros com ruptura sobre o total.'),
            new Metrica('alertas', 'Alertas', Metrica::INTEIRO, fn (Collection $i) => $alertas($i)->count(),
                'Registros de formulários marcados como alerta.'),
            new Metrica('alertas_abertos', 'Alertas em aberto', Metrica::INTEIRO,
                fn (Collection $i) => $alertas($i)->whereNull('alerta_resolvido_em')->count()),
            new Metrica('alertas_resolvidos', 'Alertas resolvidos', Metrica::INTEIRO,
                fn (Collection $i) => $alertas($i)->whereNotNull('alerta_resolvido_em')->count()),
        ];

        foreach ($this->perguntas() as $pergunta) {
            array_push($metricas, ...self::metricasDaPergunta($pergunta));
        }

        return collect($metricas)->keyBy(fn (Metrica $m) => $m->chave)->all();
    }

    /** @return Collection<int, CampoTipoRegistro> */
    private function perguntas(): Collection
    {
        return $this->formulario ? $this->formulario->campos->sortBy('ordem')->values() : collect();
    }

    private static function ehAlerta(VisitaRegistro $r): bool
    {
        return (bool) $r->tipoRegistro?->eh_alerta;
    }

    /** Resposta crua de uma pergunta (null quando não respondeu). */
    private static function resposta(VisitaRegistro $r, string $chave): ?string
    {
        $v = $r->valores_campos[$chave] ?? null;

        return $v === null || $v === '' || is_array($v) ? null : (string) $v;
    }

    /** "12,50" e "12.50" — o campo numérico é texto livre no app. */
    private static function numero(?string $v): ?float
    {
        $n = str_replace(',', '.', trim((string) $v));

        return $v !== null && is_numeric($n) ? (float) $n : null;
    }

    /** Sim/não e múltipla escolha viram campo de agrupamento e filtro. */
    private static function campoDaPergunta(CampoTipoRegistro $p): ?Campo
    {
        $chave = $p->chave;
        // A chave vai como binding (nunca concatenada no SQL).
        $json = '(visita_registros.valores_campos ->> ?)';

        return match ($p->tipo_campo) {
            TipoCampoRegistro::BOOLEANO => new Campo('resposta:'.$chave, $p->rotulo, Campo::BOOLEANO, ['igual'], grupo: self::GRUPO_PERGUNTAS,
                filtrar: fn (Builder $q, string $operador, mixed $valor) => $q->whereRaw("$json = ?", [$chave, filter_var($valor, FILTER_VALIDATE_BOOLEAN) ? '1' : '0']),
                agrupar: fn (VisitaRegistro $r) => match (self::resposta($r, $chave)) {
                    '1' => ['sim', 'Sim'],
                    '0' => ['nao', 'Não'],
                    default => ['-', 'Sem resposta'],
                }),
            TipoCampoRegistro::MULTIPLA_ESCOLHA => new Campo('resposta:'.$chave, $p->rotulo, Campo::ENUM, ['em', 'nao_em', 'vazio', 'nao_vazio'],
                opcoes: collect($p->opcoes ?? [])->mapWithKeys(fn ($o) => [(string) $o => (string) $o])->all(),
                grupo: self::GRUPO_PERGUNTAS,
                filtrar: function (Builder $q, string $operador, mixed $valor) use ($json, $chave): void {
                    $valores = array_values((array) $valor);
                    $lista = implode(', ', array_fill(0, max(1, count($valores)), '?'));
                    match ($operador) {
                        'em' => $q->whereRaw("$json in ($lista)", [$chave, ...$valores]),
                        'nao_em' => $q->where(fn (Builder $s) => $s->whereRaw("$json not in ($lista)", [$chave, ...$valores])->orWhereRaw("$json is null", [$chave])),
                        'vazio' => $q->whereRaw("coalesce($json, '') = ''", [$chave]),
                        'nao_vazio' => $q->whereRaw("coalesce($json, '') <> ''", [$chave]),
                        default => null,
                    };
                },
                agrupar: fn (VisitaRegistro $r) => ($v = self::resposta($r, $chave)) !== null ? [$v, $v] : ['-', 'Sem resposta']),
            default => null,
        };
    }

    /**
     * Operações sobre a resposta de uma pergunta. Sim/não e múltipla escolha também são campo de
     * agrupamento ("resposta:<chave>"), então a métrica aponta pra esse campo; número, moeda e texto
     * não agrupam e aparecem no editor só como campo de valor (chave da própria pergunta).
     *
     * @return list<Metrica>
     */
    private static function metricasDaPergunta(CampoTipoRegistro $p): array
    {
        $chave = $p->chave;
        $comoCampo = in_array($p->tipo_campo, [TipoCampoRegistro::BOOLEANO, TipoCampoRegistro::MULTIPLA_ESCOLHA], true);
        $campo = $comoCampo ? 'resposta:'.$chave : $chave;
        $respostas = fn (Collection $i) => $i->map(fn (VisitaRegistro $r) => self::resposta($r, $chave))->filter(fn ($v) => $v !== null)->values();
        $nova = fn (string $agregacao, string $prefixo, string $rotulo, string $formato, \Closure $conta, ?string $descricao = null) => new Metrica(
            "$prefixo:$chave", "$rotulo de {$p->rotulo}", $formato, $conta, $descricao,
            grupo: self::GRUPO_PERGUNTAS, campo: $campo, campoRotulo: $p->rotulo, agregacao: $agregacao,
        );

        $metricas = [$nova('contagem', 'respostas', 'Contagem', Metrica::INTEIRO, fn (Collection $i) => $respostas($i)->count(), 'Quantas vezes foi respondida.')];

        if ($p->tipo_campo === TipoCampoRegistro::BOOLEANO) {
            $metricas[] = $nova('percentual_sim', 'sim', '% Sim', Metrica::PERCENTUAL,
                fn (Collection $i) => self::percentual($respostas($i)->filter(fn ($v) => $v === '1')->count(), $respostas($i)->count()),
                'Sobre quem respondeu.');
        }

        // Sim/não e múltipla escolha já ganham contagem distinta e moda como campo (Entidade::metricas).
        if (! $comoCampo && in_array($p->tipo_campo, [TipoCampoRegistro::NUMERO, TipoCampoRegistro::MOEDA, TipoCampoRegistro::TEXTO], true)) {
            $numerica = $p->tipo_campo !== TipoCampoRegistro::TEXTO;
            $formatoNumero = $p->tipo_campo === TipoCampoRegistro::MOEDA ? Metrica::MOEDA : Metrica::DECIMAL;
            $valores = fn (Collection $i) => $respostas($i)->map(fn (string $v) => $numerica ? self::numero($v) : trim($v))->filter(fn ($v) => $v !== null && $v !== '')->values();

            $metricas[] = $nova('contagem_distinta', 'contagem_distinta', 'Contagem distinta', Metrica::INTEIRO,
                fn (Collection $i) => $valores($i)->unique()->count(), 'Quantas respostas diferentes.');
            $metricas[] = $nova('moda', 'moda', 'Moda', $numerica ? $formatoNumero : Metrica::TEXTO,
                fn (Collection $i) => self::moda($valores($i)), 'A resposta que mais aparece.');

            if ($numerica) {
                $arredonda = fn ($n) => $n === null ? null : round((float) $n, 2);
                foreach ([
                    'soma' => ['Soma', fn (Collection $n) => $n->isEmpty() ? null : $n->sum()],
                    'media' => ['Média', fn (Collection $n) => $n->avg()],
                    'minimo' => ['Mínimo', fn (Collection $n) => $n->min()],
                    'maximo' => ['Máximo', fn (Collection $n) => $n->max()],
                ] as $tipo => [$rotulo, $conta]) {
                    $metricas[] = $nova($tipo, $tipo, $rotulo, $formatoNumero, fn (Collection $i) => $arredonda($conta($valores($i))));
                }
            }
        }

        return $metricas;
    }
}

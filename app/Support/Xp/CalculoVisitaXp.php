<?php

namespace App\Support\Xp;

use App\Enums\CheckoutTipo;
use App\Enums\StatusPlanoAcao;
use App\Enums\StatusVisita;
use App\Enums\TipoCampoRegistro;
use App\Models\Empresa;
use App\Models\PlanoAcao;
use App\Models\Visita;
use App\Models\VisitaRegistro;
use App\Models\XpLancamento;
use App\Support\CalculadoraPontuacao;
use App\Support\Fuso;
use App\Support\RaioCheckin;
use Illuminate\Support\Collection;

/**
 * O que uma visita deveria ter na régua agora (docs/88 §5, §9, §10). Não grava nada: devolve as
 * entradas desejadas e a explicação de cada parte. `ReguaXp` compara com o que já foi lançado e
 * grava só a diferença (append-only).
 *
 * Entrada desejada: ['grupo' => ganho|perda|pendente, 'natureza' => PLANO|INICIATIVA|PERDA,
 * 'categoria', 'registro_id', 'pontos', 'competencia'].
 */
final class CalculoVisitaXp
{
    public const STATUS_PENDENTE = 'PENDENTE';

    public const STATUS_APROVADO = 'APROVADO';

    public const STATUS_REPROVADO = 'REPROVADO';

    /** @var list<array<string, mixed>> */
    public array $entradas = [];

    /** @var list<array{parte: string, rotulo: string, ganho: int, maximo: int, pendente: int, explicacao: string}> */
    public array $partes = [];

    /** @var list<array{registro_id: int, descricao: string, pontos: int, situacao: string}> */
    public array $iniciativas = [];

    public int $xpBase = 0;

    public bool $completa = false;

    public ?string $dia = null;

    public function __construct(private readonly Visita $visita, private readonly Empresa $empresa)
    {
    }

    public static function para(Visita $visita, Empresa $empresa): self
    {
        $calculo = new self($visita, $empresa);
        $calculo->calcular();

        return $calculo;
    }

    private function calcular(): void
    {
        $os = $this->visita->ordemServico;
        // Princípio 1: só conta o que está dentro de uma OS com XP congelado.
        if (! $os || $os->xp_previsto === null || $this->visita->status !== StatusVisita::FINALIZADA) {
            return;
        }

        $fusoEmpresa = Fuso::daEmpresa($this->empresa);
        $this->xpBase = (int) $os->xp_previsto;
        $this->dia = DiaXp::daOs($os, $fusoEmpresa);
        $maximos = ComposicaoXp::maximos($this->xpBase);

        $inicio = $this->visita->inicio_data;
        $fim = $this->visita->fim_data;
        $duracaoReal = $inicio && $fim ? max(0, (int) round($inicio->diffInSeconds($fim) / 60)) : 0;
        $duracaoPrevista = $os->duracao_prevista_minutos;

        // Completude: o "N de M obrigatórias" que o app mostrou no check-out; sem ele (app antigo,
        // check-out forçado), os formulários obrigatórios da OS.
        [$feitas, $total] = $this->obrigatorias();
        $fracaoCompletude = $total > 0 ? min(1, $feitas / $total) : 1.0;
        $this->completa = $total === 0 || $feitas >= $total;

        // Trava: visita curta demais não pontua (§5.2).
        $minimo = $duracaoPrevista ? (int) ceil($duracaoPrevista * ParametrosXp::tempoMinimoPercentual($this->empresa) / 100) : 0;
        if ($duracaoPrevista && $duracaoReal < $minimo) {
            $this->entradas[] = $this->entrada('perda', XpLancamento::PERDA, 'visita_curta', null, $this->xpBase, $this->dia);
            foreach (ComposicaoXp::ROTULOS_PARTE as $parte => $rotulo) {
                $this->partes[] = [
                    'parte' => $parte, 'rotulo' => $rotulo, 'ganho' => 0, 'maximo' => $maximos[$parte], 'pendente' => 0,
                    'explicacao' => "Visita de {$duracaoReal} min, abaixo do mínimo de {$minimo} min: não pontua.",
                ];
            }

            return;
        }

        $ganhos = [];
        $explicacoes = [];
        $pendentes = array_fill_keys(array_keys($maximos), 0);
        $reprovado = 0;

        // Presença (15%).
        $raio = RaioCheckin::metros($this->empresa);
        $distancia = $this->visita->inicio_distancia_metros;
        $dentro = $distancia === null || $distancia <= $raio;
        $ganhos['presenca'] = $dentro ? $maximos['presenca'] : 0;
        $explicacoes['presenca'] = $distancia === null
            ? 'Check-in sem distância registrada.'
            : 'Check-in a '.number_format((float) $distancia, 0, ',', '.').' m da loja'.($dentro ? '.' : ' (fora do raio).');

        // Completude (40%), proporcional.
        $ganhos['completude'] = (int) round($maximos['completude'] * $fracaoCompletude);
        $explicacoes['completude'] = $total > 0 ? "{$feitas} de {$total} obrigatórias feitas." : 'Sem obrigatórias nesta visita.';

        // Qualidade (20%): nota média dos formulários com pontuação, sem ruptura (§5.3).
        [$ganhos['qualidade'], $pendentes['qualidade'], $reprovado, $explicacoes['qualidade']]
            = $this->qualidade($maximos['qualidade'], $fracaoCompletude);

        // Pontualidade e tempo (20%) — só valem com a visita completa (anti-correria, §5.2).
        if (! $this->completa) {
            $ganhos['pontualidade'] = 0;
            $ganhos['tempo'] = 0;
            $explicacoes['pontualidade'] = 'Zera quando a visita não fica completa.';
            $explicacoes['tempo'] = 'Zera quando a visita não fica completa.';
        } else {
            [$ganhos['pontualidade'], $explicacoes['pontualidade']] = $this->pontualidade($maximos['pontualidade']);
            [$ganhos['tempo'], $explicacoes['tempo']] = $this->tempo($maximos['tempo'], $duracaoReal, $duracaoPrevista);
        }

        // Envio no mesmo dia (5%).
        [$ganhos['envio'], $explicacoes['envio']] = $this->envio($maximos['envio'], $fusoEmpresa);

        foreach ($maximos as $parte => $maximo) {
            $ganho = $ganhos[$parte];
            $this->entradas[] = $this->entrada('ganho', XpLancamento::PLANO, $parte, null, $ganho, $this->dia);
            $perda = $maximo - $ganho - $pendentes[$parte];
            if ($parte === 'qualidade' && $reprovado > 0) {
                $this->entradas[] = $this->entrada('perda', XpLancamento::PERDA, 'reprovado_avaliacao', null, $reprovado, $this->dia);
                $perda -= $reprovado;
            }
            $this->entradas[] = $this->entrada('perda', XpLancamento::PERDA, ComposicaoXp::PERDA_DA_PARTE[$parte], null, max(0, $perda), $this->dia);
            if ($pendentes[$parte] > 0) {
                $this->entradas[] = $this->entrada('pendente', XpLancamento::PLANO, $parte, null, $pendentes[$parte], $this->dia);
            }
            $this->partes[] = [
                'parte' => $parte, 'rotulo' => ComposicaoXp::ROTULOS_PARTE[$parte], 'ganho' => $ganho,
                'maximo' => $maximo, 'pendente' => $pendentes[$parte], 'explicacao' => $explicacoes[$parte],
            ];
        }

        // Iniciativa (§9): só dentro da visita de OS e com o dever de casa feito.
        if ($this->completa) {
            $this->iniciativa($fusoEmpresa);
        }
    }

    /** @return array{0: int, 1: int} */
    private function obrigatorias(): array
    {
        if ($this->visita->obrigatorias_total !== null) {
            return [(int) $this->visita->obrigatorias_feitas, (int) $this->visita->obrigatorias_total];
        }
        $formularios = $this->visita->ordemServico->formularios()->wherePivot('obrigatorio', true)->get();

        return [$formularios->whereNotNull('pivot.respondido_em')->count(), $formularios->count()];
    }

    /** @return Collection<int, VisitaRegistro> */
    private function registros(): Collection
    {
        return $this->visita->registros
            ->whereNull('cancelado_em')
            ->sortBy(fn (VisitaRegistro $r) => [$r->created_at?->getTimestamp(), $r->id])
            ->values();
    }

    private function avaliacao(VisitaRegistro $registro): ?string
    {
        if (! ParametrosXp::avaliacaoHabilitada($this->empresa) || ! $registro->tipoRegistro?->exige_avaliacao) {
            return null;
        }

        return $registro->avaliacao_status ?? self::STATUS_PENDENTE;
    }

    /** @return array{0: int, 1: int, 2: int, 3: string} ganho, pendente, reprovado, explicação */
    private function qualidade(int $maximo, float $fracaoCompletude): array
    {
        $formularios = $this->registros()->filter(fn (VisitaRegistro $r) => (bool) $r->tipoRegistro?->usa_pontuacao);
        if ($formularios->isEmpty()) {
            $ganho = $this->completa ? $maximo : (int) round($maximo * $fracaoCompletude);

            return [$ganho, 0, 0, $this->completa
                ? 'Sem formulário com nota: vale inteira com a visita completa.'
                : 'Sem formulário com nota: proporcional às obrigatórias.'];
        }

        $fatia = $maximo / $formularios->count();
        $ganho = 0.0;
        $pendente = 0.0;
        $reprovado = 0.0;
        $notas = [];
        foreach ($formularios as $registro) {
            $nota = CalculadoraPontuacao::calcularParaXp($registro->tipoRegistro, $registro->valores_campos) ?? 100;
            match ($this->avaliacao($registro)) {
                self::STATUS_PENDENTE => $pendente += $fatia * $nota / 100,
                self::STATUS_REPROVADO => $reprovado += $fatia,
                default => $ganho += $fatia * $nota / 100,
            };
            $notas[] = $nota;
        }
        $media = (int) round(array_sum($notas) / count($notas));
        $texto = "Nota média de {$media}% nos formulários (ruptura não tira ponto).";
        if ($pendente > 0) {
            $texto .= ' Parte aguarda a avaliação do gestor.';
        }
        if ($reprovado > 0) {
            $texto .= ' Formulário reprovado na avaliação não pontua.';
        }

        return [(int) floor($ganho), (int) round($pendente), (int) round($reprovado), $texto];
    }

    /** @return array{0: int, 1: string} */
    private function pontualidade(int $maximo): array
    {
        $os = $this->visita->ordemServico;
        if (! $os->horario_previsto || ! $this->visita->inicio_data) {
            return [$maximo, 'Sem horário marcado na OS.'];
        }
        $fusoLoja = Fuso::daLoja($this->visita->pontoVenda);
        $esperado = Fuso::instanteLocal($this->dia, substr((string) $os->horario_previsto, 0, 5), $fusoLoja);
        $tolerancia = ParametrosXp::toleranciaInicioMinutos($this->empresa);
        $atraso = (int) floor(($this->visita->inicio_data->getTimestamp() - $esperado->getTimestamp()) / 60);
        $hora = substr((string) $os->horario_previsto, 0, 5);

        return $atraso <= $tolerancia
            ? [$maximo, "Começou no horário ({$hora})."]
            : [0, "Começou {$atraso} min depois das {$hora} (tolerância de {$tolerancia} min)."];
    }

    /** @return array{0: int, 1: string} */
    private function tempo(int $maximo, int $duracaoReal, ?int $duracaoPrevista): array
    {
        if (! $duracaoPrevista) {
            return [$maximo, "{$duracaoReal} min na loja."];
        }
        $limite = (int) floor($duracaoPrevista * (100 + ParametrosXp::toleranciaDuracaoPercentual($this->empresa)) / 100);

        return $duracaoReal <= $limite
            ? [$maximo, "{$duracaoReal} de {$duracaoPrevista} min previstos."]
            : [0, 'Passou '.($duracaoReal - $duracaoPrevista)." min dos {$duracaoPrevista} min previstos."];
    }

    /** @return array{0: int, 1: string} */
    private function envio(int $maximo, string $fuso): array
    {
        if ($this->visita->checkout_tipo === CheckoutTipo::ADMIN) {
            return [0, 'Check-out feito pelo gestor.'];
        }
        $recebido = $this->visita->checkout_recebido_em ?? $this->visita->fim_data;
        $mesmoDia = DiaXp::local($recebido, $fuso) === DiaXp::local($this->visita->fim_data, $fuso);

        return $mesmoDia
            ? [$maximo, 'Enviada às '.$recebido?->copy()->setTimezone($fuso)->format('H:i').'.']
            : [0, 'Chegou ao servidor no dia seguinte.'];
    }

    /** XP de iniciativa e bônus de ação de cada registro desta visita (§9). */
    private function iniciativa(string $fuso): void
    {
        $usadosPorTipo = [];
        $competenciaRegistro = ParametrosXp::competencia($this->empresa) === ParametrosXp::COMPETENCIA_REGISTRO;

        foreach ($this->registros() as $registro) {
            $tipo = $registro->tipoRegistro;
            if (! $tipo || $tipo->xp_iniciativa <= 0) {
                continue;
            }
            $avaliacao = $this->avaliacao($registro);
            $diaRegistro = DiaXp::local($registro->created_at, $fuso) ?? $this->dia;

            // Ruptura no Mix: um XP por produto ausente (§15 item 12.1), dentro do teto.
            $unidades = max(1, $this->produtosAusentes($registro));
            $usados = $usadosPorTipo[$tipo->id] ?? 0;
            if ($tipo->xp_teto_por_visita !== null) {
                $unidades = max(0, min($unidades, $tipo->xp_teto_por_visita - $usados));
            }
            $usadosPorTipo[$tipo->id] = $usados + $unidades;

            $descricao = $tipo->descricao;
            if ($unidades === 0) {
                $this->iniciativas[] = ['registro_id' => $registro->id, 'descricao' => $descricao, 'pontos' => 0, 'situacao' => 'teto'];

                continue;
            }
            if ($this->duplicado($registro)) {
                $this->iniciativas[] = ['registro_id' => $registro->id, 'descricao' => $descricao, 'pontos' => 0, 'situacao' => 'repetido'];

                continue;
            }

            $pontos = $tipo->xp_iniciativa * $unidades;
            if ($avaliacao === self::STATUS_REPROVADO) {
                $this->iniciativas[] = ['registro_id' => $registro->id, 'descricao' => $descricao, 'pontos' => 0, 'situacao' => 'reprovado'];

                continue;
            }
            if ($avaliacao === self::STATUS_PENDENTE) {
                $this->entradas[] = $this->entrada('pendente', XpLancamento::INICIATIVA, ComposicaoXp::INICIATIVA, $registro->id, $pontos, $diaRegistro);
                $this->iniciativas[] = ['registro_id' => $registro->id, 'descricao' => $descricao, 'pontos' => $pontos, 'situacao' => 'pendente'];

                continue;
            }

            $competencia = ! $competenciaRegistro && $avaliacao === self::STATUS_APROVADO && $registro->avaliado_em
                ? DiaXp::local($registro->avaliado_em, $fuso)
                : $diaRegistro;
            $this->entradas[] = $this->entrada('ganho', XpLancamento::INICIATIVA, ComposicaoXp::INICIATIVA, $registro->id, $pontos, $competencia);
            $this->iniciativas[] = ['registro_id' => $registro->id, 'descricao' => $descricao, 'pontos' => $pontos, 'situacao' => 'ok'];

            // Informação que virou ação vale mais (§9.2).
            if ($tipo->xp_bonus_acao > 0 && ($quando = $this->virouAcao($registro))) {
                $diaAcao = $competenciaRegistro ? $diaRegistro : DiaXp::local($quando, $fuso);
                $this->entradas[] = $this->entrada('ganho', XpLancamento::INICIATIVA, ComposicaoXp::BONUS_ACAO, $registro->id, $tipo->xp_bonus_acao, $diaAcao);
                $this->iniciativas[] = ['registro_id' => $registro->id, 'descricao' => "{$descricao} virou ação", 'pontos' => $tipo->xp_bonus_acao, 'situacao' => 'ok'];
            }
        }
    }

    private function produtosAusentes(VisitaRegistro $registro): int
    {
        $ausentes = 0;
        foreach ($registro->tipoRegistro->campos as $campo) {
            if ($campo->tipo_campo !== TipoCampoRegistro::SORTIMENTO) {
                continue;
            }
            $valor = ($registro->valores_campos ?? [])[$campo->chave] ?? null;
            $mix = is_string($valor) ? json_decode($valor, true) : null;
            $ausentes += is_array($mix) && is_array($mix['ausentes'] ?? null) ? count($mix['ausentes']) : 0;
        }

        return $ausentes;
    }

    /** Antiduplicata (§9.1): mesmo formulário e produto na mesma loja pontua uma vez a cada N dias. */
    private function duplicado(VisitaRegistro $registro): bool
    {
        $dias = (int) $registro->tipoRegistro->xp_intervalo_dias;
        if ($dias <= 0 || ! $registro->created_at) {
            return false;
        }

        return XpLancamento::withoutGlobalScopes()
            ->where('empresa_id', $this->empresa->id)
            ->where('categoria', ComposicaoXp::INICIATIVA)
            ->whereIn('status', [XpLancamento::OFICIAL, XpLancamento::PENDENTE])
            ->where('pontos', '>', 0)
            ->whereHas('registro', fn ($q) => $q
                ->where('id', '!=', $registro->id)
                ->where('tipo_registro_id', $registro->tipo_registro_id)
                ->where('produto_auditoria_id', $registro->produto_auditoria_id)
                ->whereBetween('created_at', [$registro->created_at->copy()->subDays($dias), $registro->created_at])
                ->whereHas('visita', fn ($v) => $v->where('ponto_venda_id', $this->visita->ponto_venda_id)))
            ->exists();
    }

    /** Quando o registro virou ação: plano de ação aberto a partir dele ou alerta resolvido como procedente. */
    private function virouAcao(VisitaRegistro $registro): ?\Illuminate\Support\Carbon
    {
        $plano = PlanoAcao::withoutGlobalScopes()
            ->where('origem_registro_id', $registro->id)
            ->where('status', '!=', StatusPlanoAcao::CANCELADO->value)
            ->orderBy('created_at')
            ->value('created_at');
        if ($plano) {
            return \Illuminate\Support\Carbon::parse($plano);
        }
        if ($registro->alerta_resolvido_em && $registro->motivoResolucao?->procedente) {
            return $registro->alerta_resolvido_em;
        }

        return null;
    }

    /** @return array<string, mixed> */
    private function entrada(string $grupo, string $natureza, string $categoria, ?int $registroId, int $pontos, string $competencia): array
    {
        return compact('grupo', 'natureza', 'categoria', 'pontos', 'competencia') + ['registro_id' => $registroId];
    }
}

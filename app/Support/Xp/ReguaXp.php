<?php

namespace App\Support\Xp;

use App\Enums\StatusVisita;
use App\Models\Empresa;
use App\Models\OrdemServico;
use App\Models\Visita;
use App\Models\XpLancamento;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Motor da régua de XP (docs/88 §14 "Cálculo"). Recalcular é sempre comparar o que a visita
 * deveria ter (CalculoVisitaXp) com o que já foi lançado e gravar só a diferença — lançamento
 * novo, nunca edição. Disparado no check-out, na intervenção do gestor, na avaliação de
 * formulário, quando um registro vira ação e no fechamento do dia (visita não realizada).
 *
 * Nunca derruba quem chamou: falha aqui vai pro log e o check-out segue.
 */
final class ReguaXp
{
    /** Recalcula a visita e o resumo dos dias tocados. `$motivo` marca os ajustes (intervenção, avaliação). */
    public static function recalcularVisita(Visita $visita, ?string $motivo = null, ?int $autorId = null): void
    {
        self::seguro(function () use ($visita, $motivo, $autorId) {
            $empresa = Empresa::find($visita->empresa_id);
            if (! ParametrosXp::habilitado($empresa)) {
                return;
            }
            $visita = Visita::withoutGlobalScopes()
                ->with(['ordemServico', 'pontoVenda', 'registros.tipoRegistro.campos', 'registros.motivoResolucao'])
                ->find($visita->id);
            if (! $visita) {
                return;
            }

            $calculo = CalculoVisitaXp::para($visita, $empresa);
            $existentes = XpLancamento::withoutGlobalScopes()->where('visita_id', $visita->id)->get();
            $estorno = $visita->status === StatusVisita::CANCELADA;
            $dias = self::reconciliar($empresa, (int) $visita->usuario_id, $existentes, $calculo->entradas, [
                'ordem_servico_id' => $visita->ordem_servico_id,
                'visita_id' => $visita->id,
            ], $motivo, $autorId, $estorno);

            // A visita feita tira a perda "não realizada" que o fechamento do dia tinha lançado.
            if ($visita->ordem_servico_id) {
                $os = OrdemServico::withoutGlobalScopes()->find($visita->ordem_servico_id);
                if ($os) {
                    $dias = array_merge($dias, self::reconciliarNaoRealizada($empresa, $os));
                }
            }
            if ($calculo->dia) {
                $dias[] = $calculo->dia;
            }

            foreach (array_unique($dias) as $dia) {
                ResumoXp::recalcular($empresa, (int) $visita->usuario_id, $dia);
            }
        });
    }

    /**
     * Fecha um dia (rotina das 00:30): OS do dia que não viraram visita feita viram perda
     * "visita não realizada", com o motivo e o responsável que a OS já carrega (doc 59).
     */
    public static function fecharDia(Empresa $empresa, string $dia): int
    {
        if (! ParametrosXp::habilitado($empresa)) {
            return 0;
        }
        $oss = ResumoXp::osDoDia($empresa, $dia);
        foreach ($oss as $os) {
            self::reconciliarNaoRealizada($empresa, $os);
        }
        foreach (ResumoXp::usuariosDoDia($empresa, $dia) as $usuarioId) {
            ResumoXp::recalcular($empresa, $usuarioId, $dia);
        }

        return $oss->count();
    }

    /** @return list<string> dias tocados */
    private static function reconciliarNaoRealizada(Empresa $empresa, OrdemServico $os): array
    {
        if (! $os->usuario_id) {
            return [];
        }
        $fuso = DiaXp::fusoDa($empresa);
        $dia = DiaXp::daOs($os, $fuso);
        $passou = $dia < now($fuso)->toDateString();
        $feita = $os->visita_id && Visita::withoutGlobalScopes()
            ->whereKey($os->visita_id)->where('status', StatusVisita::FINALIZADA)->exists();
        $deveria = $passou && ! $feita && DiaXp::contaNoPossivel($os, $fuso) ? (int) $os->xp_previsto : 0;

        $existentes = XpLancamento::withoutGlobalScopes()
            ->where('ordem_servico_id', $os->id)->whereNull('visita_id')->get();
        $entradas = $deveria > 0 || $existentes->isNotEmpty()
            ? [['grupo' => 'perda', 'natureza' => XpLancamento::PERDA, 'categoria' => 'visita_nao_realizada', 'registro_id' => null, 'pontos' => $deveria, 'competencia' => $dia]]
            : [];

        return self::reconciliar($empresa, (int) $os->usuario_id, $existentes, $entradas, [
            'ordem_servico_id' => $os->id,
            'visita_id' => null,
        ], null, null, false);
    }

    /**
     * Grava a diferença entre o desejado e o lançado.
     *
     * @param  \Illuminate\Support\Collection<int, XpLancamento>  $existentes
     * @param  list<array<string, mixed>>  $entradas
     * @param  array{ordem_servico_id: ?int, visita_id: ?int}  $origem
     * @return list<string> dias (competências) tocados
     */
    private static function reconciliar(Empresa $empresa, int $usuarioId, $existentes, array $entradas, array $origem, ?string $motivo, ?int $autorId, bool $estorno): array
    {
        $chave = fn (string $grupo, string $categoria, ?int $registroId) => "{$grupo}|{$categoria}|".($registroId ?? '');
        $grupoDe = fn (XpLancamento $l) => $l->natureza === XpLancamento::PERDA ? 'perda' : 'ganho';

        $desejado = [];
        foreach ($entradas as $e) {
            $k = $chave($e['grupo'], $e['categoria'], $e['registro_id']);
            $desejado[$k] = isset($desejado[$k]) ? ['pontos' => $desejado[$k]['pontos'] + $e['pontos']] + $desejado[$k] : $e;
        }

        $dias = [];
        $agora = now();
        DB::transaction(function () use ($empresa, $usuarioId, $existentes, $desejado, $origem, $motivo, $autorId, $estorno, $chave, $grupoDe, &$dias, $agora) {
            // 1) Pendentes: o que deixou de estar pendente vira OFICIAL (aprovado, mesmo dia e valor) ou DESCARTADO.
            foreach ($existentes->where('status', XpLancamento::PENDENTE) as $pendente) {
                $kPendente = $chave('pendente', $pendente->categoria, $pendente->visita_registro_id);
                $kGanho = $chave('ganho', $pendente->categoria, $pendente->visita_registro_id);
                $ainda = $desejado[$kPendente] ?? null;
                if ($ainda && $ainda['pontos'] === $pendente->pontos && $ainda['competencia'] === $pendente->competencia->toDateString()) {
                    unset($desejado[$kPendente]);

                    continue;
                }
                $vira = $desejado[$kGanho] ?? null;
                $oficializa = $vira && $vira['pontos'] === $pendente->pontos && $vira['competencia'] === $pendente->competencia->toDateString()
                    && $existentes->where('status', XpLancamento::OFICIAL)->filter(fn ($l) => $chave($grupoDe($l), $l->categoria, $l->visita_registro_id) === $kGanho)->isEmpty();
                $pendente->forceFill(['status' => $oficializa ? XpLancamento::OFICIAL : XpLancamento::DESCARTADO, 'resolvido_em' => $agora])->save();
                $dias[] = $pendente->competencia->toDateString();
            }

            // 2) Oficiais (inclusive os oficializados no passo 1): soma por chave contra o desejado.
            $somas = [];
            foreach ($existentes->where('status', XpLancamento::OFICIAL) as $l) {
                $k = $chave($grupoDe($l), $l->categoria, $l->visita_registro_id);
                $somas[$k] = ($somas[$k] ?? ['pontos' => 0, 'competencia' => $l->competencia->toDateString(), 'linhas' => 0]);
                $somas[$k]['pontos'] += $l->pontos;
                $somas[$k]['linhas']++;
            }
            $chaves = array_unique(array_merge(array_keys($desejado), array_keys($somas)));
            foreach ($chaves as $k) {
                [$grupo, $categoria, $registroId] = explode('|', $k);
                $registroId = $registroId === '' ? null : (int) $registroId;
                $alvo = $desejado[$k] ?? null;

                if ($grupo === 'pendente') {
                    if ($alvo && $alvo['pontos'] > 0) {
                        self::criar($empresa, $usuarioId, $origem, $registroId, $alvo['natureza'], $categoria, $alvo['pontos'], XpLancamento::PENDENTE, $alvo['competencia'], null, null);
                        $dias[] = $alvo['competencia'];
                    }

                    continue;
                }

                $atual = $somas[$k]['pontos'] ?? 0;
                $deveria = $alvo['pontos'] ?? 0;
                $diferenca = $deveria - $atual;
                if ($diferenca === 0) {
                    continue;
                }
                $competencia = $alvo['competencia'] ?? $somas[$k]['competencia'];
                $primeira = ($somas[$k]['linhas'] ?? 0) === 0;
                $natureza = match (true) {
                    $grupo === 'perda' => XpLancamento::PERDA,
                    $primeira && $alvo !== null => $alvo['natureza'],
                    $estorno || ($diferenca < 0 && $motivo !== null) => XpLancamento::ESTORNO,
                    default => XpLancamento::AJUSTE,
                };
                $motivoLinha = in_array($natureza, [XpLancamento::ESTORNO, XpLancamento::AJUSTE], true)
                    ? ($motivo ?? 'Recalculado pelo sistema.')
                    : null;
                self::criar($empresa, $usuarioId, $origem, $registroId, $natureza, $categoria, $diferenca, XpLancamento::OFICIAL, $competencia, $motivoLinha, $autorId);
                $dias[] = $competencia;
            }
        });

        return array_values(array_unique($dias));
    }

    private static function criar(Empresa $empresa, int $usuarioId, array $origem, ?int $registroId, string $natureza, string $categoria, int $pontos, string $status, string $competencia, ?string $motivo, ?int $autorId): void
    {
        XpLancamento::withoutGlobalScopes()->create([
            'empresa_id' => $empresa->id,
            'usuario_id' => $usuarioId,
            'ordem_servico_id' => $origem['ordem_servico_id'],
            'visita_id' => $origem['visita_id'],
            'visita_registro_id' => $registroId,
            'natureza' => $natureza,
            'categoria' => $categoria,
            'pontos' => $pontos,
            'status' => $status,
            'competencia' => $competencia,
            'motivo' => $motivo,
            'criado_por_id' => $autorId,
        ]);
    }

    private static function seguro(callable $fn): void
    {
        try {
            $fn();
        } catch (Throwable $e) {
            if (app()->runningUnitTests()) {
                throw $e;
            }
            Log::error('Régua de XP: falha no recálculo', ['erro' => $e->getMessage()]);
            report($e);
        }
    }
}

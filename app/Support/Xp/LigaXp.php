<?php

namespace App\Support\Xp;

use App\Models\Empresa;
use App\Models\Supervisor;
use App\Models\Usuario;
use App\Models\XpLancamento;
use App\Models\XpLigaSemanal;
use App\Models\XpResumoDiario;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Liga semanal (docs/88 §12.4). Conta o XP da semana (plano + iniciativa oficiais), compete só
 * com a equipe, zera segunda 00h no fuso da empresa. Quem tem menos que o mínimo de visitas
 * agendadas na semana fica fora. Desempate: mais visitas completas, depois o nome. Semana fechada
 * vem de `xp_ligas_semanais` e não muda mais.
 */
final class LigaXp
{
    /**
     * @return list<array{usuario_id: int, nome: string, xp: int, plano: int, iniciativa: int, possivel: int, visitas_completas: int, posicao: int}>
     */
    public static function classificacao(Empresa $empresa, ?int $supervisorId, string $semanaInicio): array
    {
        $fechada = XpLigaSemanal::withoutGlobalScopes()
            ->with('usuario:id,nome')
            ->where('empresa_id', $empresa->id)
            ->whereDate('semana_inicio', $semanaInicio)
            ->where(fn ($q) => $supervisorId ? $q->where('supervisor_id', $supervisorId) : $q->whereNull('supervisor_id'))
            ->orderBy('posicao')
            ->get();
        if ($fechada->isNotEmpty()) {
            return $fechada->map(fn (XpLigaSemanal $l) => [
                'usuario_id' => $l->usuario_id, 'nome' => $l->usuario?->nome ?? '—', 'xp' => $l->xp, 'plano' => $l->plano,
                'iniciativa' => $l->iniciativa, 'possivel' => $l->possivel, 'visitas_completas' => $l->visitas_completas, 'posicao' => $l->posicao,
            ])->all();
        }

        return self::calcular($empresa, EquipeXp::membros($empresa, $supervisorId), $semanaInicio);
    }

    /** @param Collection<int, Usuario> $membros */
    private static function calcular(Empresa $empresa, Collection $membros, string $semanaInicio): array
    {
        $fim = Carbon::parse($semanaInicio)->addDays(6)->toDateString();
        $ids = $membros->pluck('id');
        $minimo = ParametrosXp::ligaMinimoVisitas($empresa);

        $ganhos = XpLancamento::withoutGlobalScopes()
            ->whereIn('usuario_id', $ids)
            ->where('status', XpLancamento::OFICIAL)
            ->where('natureza', '!=', XpLancamento::PERDA)
            ->whereBetween('competencia', [$semanaInicio, $fim])
            ->get(['usuario_id', 'categoria', 'pontos'])
            ->groupBy('usuario_id');
        $resumos = XpResumoDiario::withoutGlobalScopes()
            ->whereIn('usuario_id', $ids)
            ->whereBetween('dia', [$semanaInicio, $fim])
            ->get()
            ->groupBy('usuario_id');
        $agendadas = self::visitasAgendadas($empresa, $ids->all(), $semanaInicio, $fim);

        $linhas = [];
        foreach ($membros as $usuario) {
            if (($agendadas[$usuario->id] ?? 0) < $minimo) {
                continue;
            }
            $doUsuario = $ganhos->get($usuario->id, collect());
            $iniciativa = (int) $doUsuario->filter(fn ($l) => ComposicaoXp::ehIniciativa($l->categoria))->sum('pontos');
            $plano = (int) $doUsuario->sum('pontos') - $iniciativa;
            $res = $resumos->get($usuario->id, collect());
            $linhas[] = [
                'usuario_id' => $usuario->id, 'nome' => $usuario->nome, 'xp' => $plano + $iniciativa, 'plano' => $plano,
                'iniciativa' => $iniciativa, 'possivel' => (int) $res->sum('possivel'), 'visitas_completas' => (int) $res->sum('visitas_completas'),
            ];
        }
        usort($linhas, fn ($a, $b) => [$b['xp'], $b['visitas_completas'], $a['nome']] <=> [$a['xp'], $a['visitas_completas'], $b['nome']]);
        foreach ($linhas as $i => &$linha) {
            $linha['posicao'] = $i + 1;
        }

        return $linhas;
    }

    /** @return array<int, int> usuario_id => OS agendadas na semana */
    private static function visitasAgendadas(Empresa $empresa, array $ids, string $inicio, string $fim): array
    {
        $fuso = DiaXp::fusoDa($empresa);
        $contagem = [];
        $dia = Carbon::parse($inicio);
        while ($dia->toDateString() <= $fim) {
            foreach (ResumoXp::osDoDia($empresa, $dia->toDateString()) as $os) {
                $dono = ResumoXp::donoDaOs($os);
                if ($dono && in_array($dono, $ids, true) && DiaXp::contaNoPossivel($os, $fuso)) {
                    $contagem[$dono] = ($contagem[$dono] ?? 0) + 1;
                }
            }
            $dia->addDay();
        }

        return $contagem;
    }

    /** Fecha a semana que terminou (rotina de segunda 00h): grava a classificação de cada grupo. */
    public static function fecharSemana(Empresa $empresa, string $semanaInicio): int
    {
        $grupos = Supervisor::withoutGlobalScopes()->where('empresa_id', $empresa->id)->pluck('id')->push(null);
        $gravadas = 0;
        foreach ($grupos as $supervisorId) {
            if (XpLigaSemanal::withoutGlobalScopes()->where('empresa_id', $empresa->id)->whereDate('semana_inicio', $semanaInicio)
                ->where(fn ($q) => $supervisorId ? $q->where('supervisor_id', $supervisorId) : $q->whereNull('supervisor_id'))->exists()) {
                continue;
            }
            foreach (self::calcular($empresa, EquipeXp::membros($empresa, $supervisorId), $semanaInicio) as $linha) {
                XpLigaSemanal::withoutGlobalScopes()->create([
                    'empresa_id' => $empresa->id, 'supervisor_id' => $supervisorId, 'semana_inicio' => $semanaInicio,
                    'usuario_id' => $linha['usuario_id'], 'posicao' => $linha['posicao'], 'xp' => $linha['xp'], 'plano' => $linha['plano'],
                    'iniciativa' => $linha['iniciativa'], 'possivel' => $linha['possivel'], 'visitas_completas' => $linha['visitas_completas'],
                    'fechado_em' => now(),
                ]);
                $gravadas++;
            }
        }

        return $gravadas;
    }
}

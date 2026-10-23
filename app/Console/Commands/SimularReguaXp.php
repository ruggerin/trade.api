<?php

namespace App\Console\Commands;

use App\Enums\CheckoutTipo;
use App\Enums\OrigemOrdemServico;
use App\Enums\ResponsavelNaoExecucao;
use App\Enums\StatusOrdemServico;
use App\Enums\StatusVisita;
use App\Enums\UserType;
use App\Models\ClassePontoVenda;
use App\Models\Empresa;
use App\Models\MotivoNaoExecucao;
use App\Models\MotivoResolucaoAlerta;
use App\Models\OrdemServico;
use App\Models\Parametro;
use App\Models\PontoVenda;
use App\Models\ProdutoAuditoria;
use App\Models\TipoRegistro;
use App\Models\Usuario;
use App\Models\Visita;
use App\Models\VisitaRegistro;
use App\Models\XpLancamento;
use App\Models\XpLigaSemanal;
use App\Models\XpResumoDiario;
use App\Support\Fuso;
use App\Support\Xp\DiaXp;
use App\Support\Xp\LigaXp;
use App\Support\Xp\ParametrosXp;
use App\Support\MixDaVisita;
use App\Support\Xp\ReguaXp;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Simulação da régua de XP (docs/88) numa base de TESTE: um mês de agenda, visitas e registros
 * dos promotores da empresa, com perfis diferentes (disciplinado, mediano, com problemas), pra ver
 * o painel e o app com dado. Tudo que cria leva `[simulação XP]` na OS e sai com `--limpar`.
 * Recusa rodar em produção.
 */
class SimularReguaXp extends Command
{
    protected $signature = 'xp:simular
        {empresa : Nome fantasia (ou parte) ou uuid da empresa}
        {--de= : Primeiro dia (YYYY-MM-DD); padrão: dia 1 do mês}
        {--ate= : Último dia (YYYY-MM-DD); padrão: hoje}
        {--limpar : Só remove o que uma simulação anterior criou}
        {--acrescentar : Mantém a simulação anterior e só refaz o período (padrão: só hoje)}
        {--semente=88 : Semente do sorteio (mesma semente, mesmo resultado)}';

    protected $description = 'Gera um mês simulado de agenda, visitas e registros pra testar a régua de XP (só base de teste)';

    public const MARCA = '[simulação XP]';

    /** Perfis por ordem dos promotores: chance de fazer, atrasar, ficar incompleta, fora do raio, enviar no dia seguinte, estourar, curta, iniciativa. */
    private const PERFIS = [
        ['nome' => 'disciplinado', 'faz' => 0.96, 'atrasa' => 0.08, 'incompleta' => 0.06, 'fora' => 0.03, 'envio' => 0.02, 'estoura' => 0.08, 'curta' => 0.0, 'iniciativa' => 1.0],
        ['nome' => 'mediano', 'faz' => 0.86, 'atrasa' => 0.25, 'incompleta' => 0.15, 'fora' => 0.08, 'envio' => 0.07, 'estoura' => 0.18, 'curta' => 0.02, 'iniciativa' => 0.7],
        ['nome' => 'com problemas', 'faz' => 0.72, 'atrasa' => 0.4, 'incompleta' => 0.3, 'fora' => 0.15, 'envio' => 0.15, 'estoura' => 0.25, 'curta' => 0.06, 'iniciativa' => 0.4],
    ];

    public function handle(): int
    {
        if (app()->environment('production')) {
            $this->error('Simulação não roda em produção.');

            return self::FAILURE;
        }

        $busca = (string) $this->argument('empresa');
        $empresa = \Illuminate\Support\Str::isUuid($busca)
            ? Empresa::where('uuid', $busca)->first()
            : Empresa::where('nome_fantasia', 'ilike', "%{$busca}%")->first();
        if (! $empresa) {
            $this->error('Empresa não encontrada.');

            return self::FAILURE;
        }
        $fuso = Fuso::daEmpresa($empresa);
        $agora = now($fuso);
        $acrescentar = (bool) $this->option('acrescentar');
        $de = $this->option('de') ?? ($acrescentar ? $agora->toDateString() : $agora->copy()->startOfMonth()->toDateString());
        $ate = $this->option('ate') ?? $agora->toDateString();

        $removidas = $acrescentar ? $this->limpar($empresa, [$de, $ate], $fuso) : $this->limpar($empresa);
        $this->info("Simulação anterior removida: {$removidas} OS.");
        if ($this->option('limpar')) {
            return self::SUCCESS;
        }

        mt_srand((int) $this->option('semente'));
        $this->prepararEmpresa($empresa);

        $promotores = Usuario::withoutGlobalScopes()->where('empresa_id', $empresa->id)
            ->where('user_type', UserType::PROMOTOR)->where('ativo', true)->orderBy('id')->get();
        if ($promotores->isEmpty()) {
            $this->error('A empresa não tem promotor ativo.');

            return self::FAILURE;
        }
        $lojas = PontoVenda::withoutGlobalScopes()->where('empresa_id', $empresa->id)->where('ativo', true)
            ->whereNotNull('latitude')->orderBy('id')->get();
        $rotas = $this->montarRotas($promotores, $lojas);
        $tipos = TipoRegistro::withoutGlobalScopes()->where('empresa_id', $empresa->id)->where('ativo', true)->with('campos')->get()->keyBy('descricao');
        $produtos = ProdutoAuditoria::withoutGlobalScopes()->where('empresa_id', $empresa->id)->where('ativo', true)->pluck('id', 'uuid');
        $motivoProcedente = MotivoResolucaoAlerta::withoutGlobalScopes()->where('empresa_id', $empresa->id)->where('procedente', true)->first();
        $motivosNaoExecucao = MotivoNaoExecucao::withoutGlobalScopes()->where('empresa_id', $empresa->id)->pluck('id');
        $gestor = Usuario::withoutGlobalScopes()->where('empresa_id', $empresa->id)->whereIn('user_type', [UserType::ADMIN, UserType::GESTOR])->first();

        $visitas = collect();
        $dias = 0;
        for ($dia = Carbon::parse($de, $fuso); $dia->toDateString() <= $ate; $dia->addDay()) {
            if ($dia->isSunday()) {
                continue;
            }
            $dias++;
            // A equipe melhora um pouco a cada mês — pra o comparativo mensal ter o que mostrar.
            $mesesAtras = (int) Carbon::parse($dia->toDateString())->startOfMonth()->diffInMonths($agora->copy()->startOfMonth());
            foreach ($promotores as $i => $promotor) {
                $perfil = $this->perfilNoMes(self::PERFIS[min($i, count(self::PERFIS) - 1)], $mesesAtras);
                foreach ($rotas[$promotor->id][$dia->dayOfWeekIso] ?? [] as $parada) {
                    $visita = $this->simularParada($empresa, $promotor, $parada, $dia->copy(), $agora, $perfil, $tipos, $produtos, $motivoProcedente, $motivosNaoExecucao, $gestor);
                    if ($visita) {
                        $visitas->push($visita);
                    }
                }
            }
        }

        $this->info("Agenda criada: {$dias} dias úteis, {$visitas->count()} visitas feitas. Calculando a régua...");
        $barra = $this->output->createProgressBar($visitas->count());
        foreach ($visitas as $visita) {
            ReguaXp::recalcularVisita($visita);
            MixDaVisita::gravar($visita);
            $barra->advance();
        }
        $barra->finish();
        $this->newLine();

        for ($dia = Carbon::parse($de); $dia->toDateString() < $agora->toDateString() && $dia->toDateString() <= $ate; $dia->addDay()) {
            ReguaXp::fecharDia($empresa, $dia->toDateString());
        }
        ReguaXp::fecharDia($empresa, $agora->toDateString());
        for ($segunda = Carbon::parse(DiaXp::inicioDaSemana($de)); $segunda->copy()->addDays(6)->toDateString() < $agora->toDateString(); $segunda->addWeek()) {
            LigaXp::fecharSemana($empresa, $segunda->toDateString());
        }

        $resumo = XpResumoDiario::withoutGlobalScopes()->where('empresa_id', $empresa->id)->whereBetween('dia', [$de, $ate]);
        $this->table(['Possível', 'Realizado', 'Iniciativa', 'Aguardando avaliação'], [[
            (clone $resumo)->sum('possivel'), (clone $resumo)->sum('realizado'), (clone $resumo)->sum('iniciativa'), (clone $resumo)->sum('pendente'),
        ]]);
        $this->info('Pronto. Admin: Relatórios → Régua de XP. App: Agenda → "Ver pontuação". Pra remover: php artisan xp:simular "'.$empresa->nome_fantasia.'" --limpar');

        return self::SUCCESS;
    }

    /** Cada mês pra trás, um pouco pior: menos visitas feitas, mais atraso e incompletas. */
    private function perfilNoMes(array $perfil, int $mesesAtras): array
    {
        if ($mesesAtras <= 0) {
            return $perfil;
        }
        $piora = 0.06 * $mesesAtras;
        $perfil['faz'] = max(0.4, $perfil['faz'] - $piora);
        foreach (['atrasa', 'incompleta', 'fora', 'estoura'] as $chave) {
            $perfil[$chave] = min(0.9, $perfil[$chave] + $piora);
        }
        $perfil['iniciativa'] = max(0.2, $perfil['iniciativa'] - $piora);

        return $perfil;
    }

    /** Classes, parâmetros, XP nos formulários e motivos — o mínimo pra régua ter o que mostrar. */
    private function prepararEmpresa(Empresa $empresa): void
    {
        $empresa->update(['controle_xp_habilitado' => true]);
        ClassePontoVenda::semearExemplo($empresa);

        foreach (['XP_VISIVEL_PROMOTOR' => 'true', 'AVALIACAO_FORMULARIO_HABILITADA' => 'true', 'XP_LIGA_MINIMO_VISITAS' => '5'] as $chave => $valor) {
            Parametro::withoutGlobalScopes()->updateOrCreate(['empresa_id' => $empresa->id, 'chave' => $chave], ['valor' => $valor, 'ativo' => true]);
        }
        ParametrosXp::limparCache();

        $xp = [
            'Ponto extra' => ['xp_iniciativa' => 80, 'xp_teto_por_visita' => 2, 'exige_avaliacao' => true],
            'Ruptura' => ['xp_iniciativa' => 10, 'xp_bonus_acao' => 30, 'xp_teto_por_visita' => 5, 'xp_intervalo_dias' => 7],
            'Proximo Vencimento' => ['xp_iniciativa' => 15, 'xp_teto_por_visita' => 3],
            'Avaria' => ['xp_iniciativa' => 10, 'xp_teto_por_visita' => 3],
            'Auditoria Loja Perfeita Amaciante' => ['usa_pontuacao' => true],
        ];
        foreach ($xp as $descricao => $campos) {
            TipoRegistro::withoutGlobalScopes()->where('empresa_id', $empresa->id)->where('descricao', $descricao)->update($campos);
        }

        MotivoResolucaoAlerta::withoutGlobalScopes()->firstOrCreate(
            ['empresa_id' => $empresa->id, 'descricao' => 'Produto reposto na gôndola'],
            ['procedente' => true, 'ativo' => true],
        )->update(['procedente' => true]);
        foreach (['Loja fechada', 'Chuva forte', 'Problema no deslocamento', 'Convocação da empresa'] as $descricao) {
            MotivoNaoExecucao::withoutGlobalScopes()->firstOrCreate(['empresa_id' => $empresa->id, 'descricao' => $descricao], ['ativo' => true]);
        }
    }

    /**
     * Uma rota fixa por promotor e dia da semana (seg a sáb), com a classe de cada loja sorteada
     * (poucos chefões, muitos soldados) e horários encadeados com 20 min de deslocamento.
     *
     * @return array<int, array<int, list<array{loja: PontoVenda, horario: string}>>>
     */
    private function montarRotas(Collection $promotores, Collection $lojas): array
    {
        $classes = ClassePontoVenda::withoutGlobalScopes()->where('empresa_id', $promotores->first()->empresa_id)->where('ativo', true)->orderByDesc('xp_base')->get()->values();
        $embaralhadas = $lojas->shuffle(mt_rand())->values();
        $rotas = [];
        $proxima = 0;
        foreach ($promotores as $promotor) {
            for ($diaSemana = 1; $diaSemana <= 6; $diaSemana++) {
                $minuto = 8 * 60;
                $paradas = [];
                while (count($paradas) < 6 && $embaralhadas->isNotEmpty()) {
                    $loja = $embaralhadas[$proxima % $embaralhadas->count()];
                    $proxima++;
                    $sorteio = mt_rand(1, 100);
                    $classe = $classes->count() >= 3
                        ? ($sorteio <= 12 ? $classes[0] : ($sorteio <= 45 ? $classes[1] : $classes[2]))
                        : $classes->first();
                    if (! $loja->classe_id && $classe) {
                        $loja->forceFill(['classe_id' => $classe->id])->saveQuietly();
                    }
                    $duracao = ClassePontoVenda::withoutGlobalScopes()->find($loja->classe_id)?->duracao_padrao_minutos ?? 30;
                    if ($minuto + $duracao > 17 * 60 + 30) {
                        break;
                    }
                    $paradas[] = ['loja' => $loja, 'horario' => sprintf('%02d:%02d', intdiv($minuto, 60), $minuto % 60)];
                    $minuto += $duracao + 20;
                }
                $rotas[$promotor->id][$diaSemana] = $paradas;
            }
        }

        return $rotas;
    }

    private function simularParada(Empresa $empresa, Usuario $promotor, array $parada, Carbon $dia, Carbon $agora, array $perfil, Collection $tipos, Collection $produtos, ?MotivoResolucaoAlerta $procedente, Collection $motivosNaoExecucao, ?Usuario $gestor): ?Visita
    {
        $fuso = $dia->getTimezone()->getName();
        /** @var PontoVenda $loja */
        $loja = $parada['loja'];
        // Prazo do dia inteiro, gravado como a geração da agenda grava (doc 50).
        $os = OrdemServico::withoutGlobalScopes()->create([
            'empresa_id' => $empresa->id,
            'ponto_venda_id' => $loja->id,
            'usuario_id' => $promotor->id,
            'origem' => OrigemOrdemServico::MANUAL,
            'obrigatoria' => true,
            'horario_previsto' => $parada['horario'],
            'prazo_inicio' => $dia->copy()->startOfDay(),
            'prazo_fim' => $dia->copy()->endOfDay(),
            'status' => StatusOrdemServico::PENDENTE,
            'observacao' => self::MARCA,
        ]);
        $previsto = Carbon::parse($dia->toDateString().' '.$parada['horario'], $fuso);
        $hoje = $dia->toDateString() === $agora->toDateString();

        // Hoje: o que ainda não chegou a hora fica pendente na agenda.
        if ($hoje && $previsto->gt($agora)) {
            return null;
        }
        if (mt_rand() / mt_getrandmax() > $perfil['faz']) {
            // Hoje não cancela (o gestor ainda não teria feito isso): fica atrasada na agenda.
            if (! $hoje && mt_rand(0, 1) === 1) {
                $responsavel = [ResponsavelNaoExecucao::LOJA, ResponsavelNaoExecucao::PROMOTOR, ResponsavelNaoExecucao::PROMOTOR, ResponsavelNaoExecucao::EMPRESA][mt_rand(0, 3)];
                $os->update([
                    'status' => StatusOrdemServico::CANCELADA,
                    'cancelada_em' => $previsto->copy()->addHours(2)->utc(),
                    'cancelada_por_id' => $gestor?->id,
                    'motivo_cancelamento_id' => $motivosNaoExecucao->isNotEmpty() ? $motivosNaoExecucao->random() : null,
                    'responsavel_nao_execucao' => $responsavel->value,
                ]);
            }

            return null;
        }

        $chance = fn (string $chave) => mt_rand() / mt_getrandmax() < $perfil[$chave];
        $duracaoPrevista = (int) ($os->duracao_prevista_minutos ?? 30);
        $atraso = $chance('atrasa') ? mt_rand(35, 95) : mt_rand(-8, 12);
        $inicio = $previsto->copy()->addMinutes($atraso);
        $fator = $chance('curta') ? mt_rand(8, 20) / 100 : ($chance('estoura') ? mt_rand(130, 170) / 100 : mt_rand(70, 112) / 100);
        $fim = $inicio->copy()->addMinutes(max(3, (int) round($duracaoPrevista * $fator)));
        // Hoje, atrasou tanto que ainda não começou: pendente (atrasada) na agenda.
        if ($hoje && $inicio->gt($agora)) {
            return null;
        }
        // Hoje, começou e ainda não terminou: visita em andamento, sem check-out (não entra na
        // régua até o check-out, como no app).
        if ($hoje && $fim->gt($agora)) {
            $aberta = Visita::withoutGlobalScopes()->create([
                'empresa_id' => $empresa->id,
                'ponto_venda_id' => $loja->id,
                'usuario_id' => $promotor->id,
                'ordem_servico_id' => $os->id,
                'status' => StatusVisita::ABERTA,
                'inicio_data' => $inicio->copy()->utc(),
                'inicio_latitude' => $loja->latitude,
                'inicio_longitude' => $loja->longitude,
                'inicio_distancia_metros' => mt_rand(4, 90),
                'checkin_recebido_em' => $inicio->copy()->addMinute()->utc(),
            ]);
            $os->update(['status' => StatusOrdemServico::EM_ANDAMENTO, 'visita_id' => $aberta->id]);
            $this->registros($aberta, $tipos, $produtos, $inicio, $agora->copy(), $agora, $perfil, $procedente, $gestor, $duracaoPrevista >= 45);

            return null;
        }
        $total = mt_rand(3, 6);
        $feitas = $chance('incompleta') ? mt_rand(1, $total - 1) : $total;
        $recebido = $chance('envio') && ! $hoje ? $fim->copy()->addDay()->setTime(7, mt_rand(0, 59)) : $fim->copy()->addMinutes(mt_rand(1, 6));

        $visita = Visita::withoutGlobalScopes()->create([
            'empresa_id' => $empresa->id,
            'ponto_venda_id' => $loja->id,
            'usuario_id' => $promotor->id,
            'ordem_servico_id' => $os->id,
            'status' => StatusVisita::FINALIZADA,
            'inicio_data' => $inicio->copy()->utc(),
            'inicio_latitude' => $loja->latitude,
            'inicio_longitude' => $loja->longitude,
            'inicio_distancia_metros' => $chance('fora') ? mt_rand(320, 900) : mt_rand(4, 90),
            'fim_data' => $fim->copy()->utc(),
            'fim_latitude' => $loja->latitude,
            'fim_longitude' => $loja->longitude,
            'fim_distancia_metros' => mt_rand(4, 90),
            'checkout_tipo' => CheckoutTipo::PROMOTOR,
            'checkin_recebido_em' => $inicio->copy()->addMinute()->utc(),
            'checkout_recebido_em' => $recebido->copy()->utc(),
            'obrigatorias_feitas' => $feitas,
            'obrigatorias_total' => $total,
        ]);
        $os->update(['status' => StatusOrdemServico::CONCLUIDA, 'visita_id' => $visita->id]);

        $this->registros($visita, $tipos, $produtos, $inicio, $fim, $agora, $perfil, $procedente, $gestor, $duracaoPrevista >= 45);

        return $visita;
    }

    private function registros(Visita $visita, Collection $tipos, Collection $produtos, Carbon $inicio, Carbon $fim, Carbon $agora, array $perfil, ?MotivoResolucaoAlerta $procedente, ?Usuario $gestor, bool $lojaGrande): void
    {
        $momento = fn () => $inicio->copy()->addSeconds(mt_rand(60, max(61, $inicio->diffInSeconds($fim) - 60)));
        $iniciativa = $perfil['iniciativa'];
        $criar = function (TipoRegistro $tipo, array $extra = []) use ($visita, $momento) {
            $quando = $momento();
            $registro = new VisitaRegistro(['visita_id' => $visita->id, 'tipo_registro_id' => $tipo->id] + $extra);
            $registro->created_at = $quando->copy()->utc();
            $registro->recebido_em = $quando->copy()->addMinutes(2)->utc();
            $registro->save();

            return $registro;
        };
        $produto = fn () => $produtos->isNotEmpty() ? $produtos->random() : null;

        // Auditoria com nota (qualidade): mais caprichada no perfil disciplinado.
        if (($auditoria = $tipos->get('Auditoria Loja Perfeita Amaciante')) && ($lojaGrande || mt_rand(1, 100) <= 35)) {
            $acerto = 0.55 + 0.4 * $iniciativa;
            $sim = fn () => mt_rand() / mt_getrandmax() < $acerto ? '1' : '0';
            $uuids = $produtos->keys()->shuffle()->take(6)->values();
            $ausentes = mt_rand(1, 100) <= 30 ? $uuids->take(mt_rand(1, 2))->all() : [];
            $criar($auditoria, ['valores_campos' => [
                'frentes' => (string) mt_rand(2, 12),
                'mix' => json_encode(['presentes' => array_values(array_diff($uuids->all(), $ausentes)), 'ausentes' => array_values($ausentes)]),
                'planograma' => $sim(), 'promocionado' => $sim(), 'ponto_extra' => $sim(),
            ]]);
        }

        // Ruptura (alerta) — às vezes resolvida como procedente, o que dá o bônus de ação.
        if (($ruptura = $tipos->get('Ruptura')) && mt_rand(1, 100) <= 35) {
            foreach (range(1, mt_rand(1, 2)) as $_) {
                $registro = $criar($ruptura, ['ruptura' => true, 'produto_auditoria_id' => $produto()]);
                if ($procedente && mt_rand(1, 100) <= 45 && $registro->created_at->lt($agora->copy()->subHours(3))) {
                    $registro->forceFill([
                        'alerta_resolvido_em' => $registro->created_at->copy()->addHours(mt_rand(2, 30)),
                        'alerta_resolvido_por_id' => $gestor?->id,
                        'alerta_motivo_id' => $procedente->id,
                    ])->save();
                }
            }
        }

        // Ponto extra (conquista) — passa pela avaliação; os antigos já foram avaliados.
        if (($pontoExtra = $tipos->get('Ponto extra')) && mt_rand() / mt_getrandmax() < 0.18 * $iniciativa + 0.04) {
            $registro = $criar($pontoExtra, ['valores_campos' => ['quantidade' => (string) mt_rand(1, 4), 'valor' => (string) mt_rand(50, 400)]]);
            $idade = $registro->created_at->diffInDays($agora);
            $status = $idade < 2 ? 'PENDENTE' : (mt_rand(1, 100) <= 80 ? 'APROVADO' : 'REPROVADO');
            $registro->forceFill([
                'avaliacao_status' => $status,
                'avaliado_por_id' => $status === 'PENDENTE' ? null : $gestor?->id,
                'avaliado_em' => $status === 'PENDENTE' ? null : $registro->created_at->copy()->addDays(mt_rand(1, 2)),
                'avaliacao_motivo' => $status === 'REPROVADO' ? 'A foto não mostra o ponto extra montado.' : null,
            ])->save();
        }

        if (($vencimento = $tipos->get('Proximo Vencimento')) && mt_rand() / mt_getrandmax() < 0.2 * $iniciativa + 0.05) {
            $criar($vencimento, ['produto_auditoria_id' => $produto(), 'valores_campos' => ['validade' => $fim->copy()->addDays(mt_rand(5, 25))->format('d/m/Y')]]);
        }
        if (($avaria = $tipos->get('Avaria')) && mt_rand(1, 100) <= 10) {
            $criar($avaria, ['produto_auditoria_id' => $produto(), 'valores_campos' => ['obervacao' => 'Embalagem amassada no estoque']]);
        }
        if (($foto = $tipos->get('Foto')) && mt_rand(1, 100) <= 50) {
            $criar($foto);
        }
    }

    /** Remove tudo que uma simulação anterior criou nesta empresa (OS marcadas e o que pendura nelas). */
    /** Remove a simulação anterior; com `$periodo`, só as OS daqueles dias (o resto fica). */
    private function limpar(Empresa $empresa, ?array $periodo = null, ?string $fuso = null): int
    {
        return DB::transaction(function () use ($empresa, $periodo, $fuso) {
            $oss = OrdemServico::withoutGlobalScopes()->where('empresa_id', $empresa->id)->where('observacao', self::MARCA)
                ->when($periodo, fn ($q) => $q->whereBetween('prazo_inicio', [
                    Carbon::parse($periodo[0], $fuso)->startOfDay(), Carbon::parse($periodo[1], $fuso)->endOfDay(),
                ]))
                ->get(['id', 'visita_id']);
            if ($oss->isEmpty()) {
                return 0;
            }
            $osIds = $oss->pluck('id');
            $visitaIds = Visita::withoutGlobalScopes()->whereIn('ordem_servico_id', $osIds)->pluck('id');

            XpLancamento::withoutGlobalScopes()->where(fn ($q) => $q->whereIn('ordem_servico_id', $osIds)->orWhereIn('visita_id', $visitaIds))->delete();
            VisitaRegistro::whereIn('visita_id', $visitaIds)->delete();
            OrdemServico::withoutGlobalScopes()->whereIn('id', $osIds)->update(['visita_id' => null]);
            Visita::withoutGlobalScopes()->whereIn('id', $visitaIds)->delete();
            OrdemServico::withoutGlobalScopes()->whereIn('id', $osIds)->delete();
            // Resumo e liga só existem por causa da régua: recalculáveis a qualquer momento. Só
            // acrescentando, ficam os dias e as semanas de fora do período.
            XpResumoDiario::withoutGlobalScopes()->where('empresa_id', $empresa->id)
                ->when($periodo, fn ($q) => $q->whereBetween('dia', $periodo))->delete();
            if (! $periodo) {
                XpLigaSemanal::withoutGlobalScopes()->where('empresa_id', $empresa->id)->delete();
            }

            return $oss->count();
        });
    }
}

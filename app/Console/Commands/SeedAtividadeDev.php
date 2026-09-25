<?php

namespace App\Console\Commands;

use App\Models\PlanoAcao;
use App\Models\PontoVenda;
use App\Models\ProdutoAuditoria;
use App\Models\TipoRegistro;
use App\Models\Usuario;
use App\Models\Visita;
use App\Models\VisitaRegistro;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Enche a base local de dev com visitas/registros realistas (rupturas, observações) dos
 * promotores já cadastrados, pros últimos N dias + hoje — pra Operação do Dia, o Painel de
 * Atividades e a lista de Planos de Ação terem dado de verdade em vez de tela vazia.
 *
 * Só roda fora de produção. Rastreia tudo que cria num manifesto
 * (storage/app/dev-seed/atividade-manifest.json) pra `--limpar` apagar exatamente o que foi
 * gerado aqui, nunca dado real que o usuário tenha criado testando a mão.
 */
class SeedAtividadeDev extends Command
{
    protected $signature = 'dev:seed-atividade
        {--dias=7 : Quantos dias pra trás gerar, além de hoje}
        {--visitas-por-dia=4 : Média de visitas por promotor por dia}
        {--empresa=2 : ID da empresa a povoar}
        {--limpar : Só apaga o que foi gerado numa execução anterior (não gera nada de novo)}';

    protected $description = 'Gera visitas/registros de teste (rupturas, observações) pros promotores existentes — só dev.';

    private const MANIFESTO = 'dev-seed/atividade-manifest.json';

    private const OBSERVACOES_RUPTURA = [
        'Gôndola vazia, sem previsão do repositor.',
        'Sem estoque no depósito da loja.',
        'Produto fora da gôndola há pelo menos 2 dias, segundo o gerente.',
        'Concorrente ocupou o espaço da gôndola.',
        'Cliente reclamou que não encontra o produto há uma semana.',
        'Encalhado no estoque, mas não repõe a gôndola.',
    ];

    private const OBSERVACOES_GERAIS = [
        'Loja organizada, sem pendências.',
        'Gerente pediu mais material de ponto extra.',
        'Planograma seguido corretamente nesta visita.',
        'Concorrência com ação promocional forte esta semana.',
        'Loja em reforma, acesso à gôndola principal limitado.',
    ];

    public function handle(): int
    {
        if (app()->environment('production')) {
            $this->error('Este comando não roda em produção.');

            return self::FAILURE;
        }

        if ($this->option('limpar')) {
            $this->limpar();

            return self::SUCCESS;
        }

        $empresaId = (int) $this->option('empresa');
        $dias = max(0, (int) $this->option('dias'));
        $mediaVisitas = max(1, (int) $this->option('visitas-por-dia'));

        $promotores = Usuario::where('empresa_id', $empresaId)->where('user_type', 'PROMOTOR')->where('ativo', true)->get();
        $pdvs = PontoVenda::where('empresa_id', $empresaId)->get();
        $produtos = ProdutoAuditoria::where('empresa_id', $empresaId)->get();
        $tipoRuptura = TipoRegistro::where('empresa_id', $empresaId)->where('descricao', 'Ruptura')->first();
        $tipoObservacao = TipoRegistro::where('empresa_id', $empresaId)->where('descricao', 'Observação')->first();
        $admin = Usuario::where('empresa_id', $empresaId)->where('user_type', 'ADMIN')->first();

        if ($promotores->isEmpty() || $pdvs->isEmpty() || ! $tipoRuptura) {
            $this->error('Empresa sem promotor ativo, PDV ou tipo de registro "Ruptura" o suficiente pra seedar.');

            return self::FAILURE;
        }

        $manifesto = $this->lerManifesto();
        $hoje = Carbon::today();
        $totalVisitas = 0;
        $totalRegistros = 0;
        $totalRupturas = 0;

        for ($offset = $dias; $offset >= 0; $offset--) {
            $dia = $hoje->copy()->subDays($offset);
            $ehHoje = $offset === 0;

            foreach ($promotores as $promotor) {
                $qtd = max(1, $mediaVisitas + random_int(-1, 1));
                $pdvsDoDia = $pdvs->random(min($qtd, $pdvs->count()));
                $horario = $dia->copy()->setTime(8, random_int(0, 60));

                $visitasDoDia = [];
                foreach ($pdvsDoDia as $pdv) {
                    $horario->addMinutes(random_int(40, 100));

                    // Hoje: não cria check-in no futuro (relativo a agora).
                    if ($ehHoje && $horario->greaterThan(now())) {
                        break;
                    }

                    $duracaoMin = random_int(20, 60);
                    $fim = $horario->copy()->addMinutes($duracaoMin);
                    $aindaAberta = $ehHoje && $fim->greaterThan(now());

                    $visita = Visita::create([
                        'empresa_id' => $empresaId,
                        'ponto_venda_id' => $pdv->id,
                        'usuario_id' => $promotor->id,
                        'status' => $aindaAberta ? 'ABERTA' : 'FINALIZADA',
                        'inicio_data' => $horario->copy(),
                        'inicio_latitude' => $pdv->latitude,
                        'inicio_longitude' => $pdv->longitude,
                        'inicio_distancia_metros' => 0,
                        'fim_data' => $aindaAberta ? null : $fim,
                        'fim_latitude' => $aindaAberta ? null : $pdv->latitude,
                        'fim_longitude' => $aindaAberta ? null : $pdv->longitude,
                        'fim_distancia_metros' => $aindaAberta ? null : 0,
                        'checkout_tipo' => $aindaAberta ? null : 'PROMOTOR',
                    ]);
                    $manifesto['visitas'][] = $visita->id;
                    $totalVisitas++;
                    $visitasDoDia[] = $horario->copy();

                    // ~35% de chance de ruptura, ~50% de observação geral — pode ter as duas.
                    if ($produtos->isNotEmpty() && random_int(1, 100) <= 35) {
                        $resolvida = ! $ehHoje && random_int(1, 100) <= 70;
                        $registro = VisitaRegistro::create([
                            'visita_id' => $visita->id,
                            'tipo_registro_id' => $tipoRuptura->id,
                            'produto_auditoria_id' => $produtos->random()->id,
                            'ruptura' => true,
                            'observacao' => self::OBSERVACOES_RUPTURA[array_rand(self::OBSERVACOES_RUPTURA)],
                            'alerta_resolvido_em' => $resolvida ? $horario->copy()->addHours(random_int(2, 30)) : null,
                            'alerta_resolvido_por_id' => $resolvida && $admin ? $admin->id : null,
                            'created_at' => $horario->copy(),
                            'updated_at' => $horario->copy(),
                        ]);
                        $manifesto['registros'][] = $registro->id;
                        $totalRegistros++;
                        $totalRupturas++;
                    }

                    if ($tipoObservacao && random_int(1, 100) <= 50) {
                        $registro = VisitaRegistro::create([
                            'visita_id' => $visita->id,
                            'tipo_registro_id' => $tipoObservacao->id,
                            'observacao' => self::OBSERVACOES_GERAIS[array_rand(self::OBSERVACOES_GERAIS)],
                            'created_at' => $horario->copy(),
                            'updated_at' => $horario->copy(),
                        ]);
                        $manifesto['registros'][] = $registro->id;
                        $totalRegistros++;
                    }
                }

                // Hoje: deixa ~70% dos promotores com uma visita em andamento AGORA (começada
                // nos últimos 5-35 min), pra Operação do Dia mostrar bloco "atual" no Gantt em
                // vez de só histórico do dia. As de cima já cobrem o resto do dia.
                if ($ehHoje && random_int(1, 100) <= 70) {
                    $pdvAtual = $pdvs->random();
                    $inicioAtual = now()->subMinutes(random_int(5, 35));

                    $visita = Visita::create([
                        'empresa_id' => $empresaId,
                        'ponto_venda_id' => $pdvAtual->id,
                        'usuario_id' => $promotor->id,
                        'status' => 'ABERTA',
                        'inicio_data' => $inicioAtual,
                        'inicio_latitude' => $pdvAtual->latitude,
                        'inicio_longitude' => $pdvAtual->longitude,
                        'inicio_distancia_metros' => 0,
                    ]);
                    $manifesto['visitas'][] = $visita->id;
                    $totalVisitas++;
                }
            }
        }

        $this->salvarManifesto($manifesto);

        $this->info("Pronto: {$totalVisitas} visitas, {$totalRegistros} registros ({$totalRupturas} rupturas) — hoje + {$dias} dia(s) anteriores, empresa {$empresaId}.");
        $this->line('Pra apagar tudo isso depois: php artisan dev:seed-atividade --limpar');

        return self::SUCCESS;
    }

    private function limpar(): void
    {
        $manifesto = $this->lerManifesto();

        if (empty($manifesto['visitas']) && empty($manifesto['registros'])) {
            $this->info('Nada pra limpar — nenhum manifesto de seed encontrado.');

            return;
        }

        $registros = $manifesto['registros'] ?? [];
        $visitas = $manifesto['visitas'] ?? [];

        $planos = PlanoAcao::whereIn('origem_registro_id', $registros)->count();
        PlanoAcao::whereIn('origem_registro_id', $registros)->delete();

        VisitaRegistro::whereIn('id', $registros)->delete();
        Visita::whereIn('id', $visitas)->delete();

        $this->info(sprintf(
            'Limpo: %d visitas, %d registros, %d plano(s) de ação vinculado(s).',
            count($visitas),
            count($registros),
            $planos,
        ));

        $this->salvarManifesto(['visitas' => [], 'registros' => []]);
    }

    /** @return array{visitas: list<int>, registros: list<int>} */
    private function lerManifesto(): array
    {
        $path = storage_path('app/'.self::MANIFESTO);
        if (! file_exists($path)) {
            return ['visitas' => [], 'registros' => []];
        }

        $dados = json_decode(file_get_contents($path), true);

        return [
            'visitas' => $dados['visitas'] ?? [],
            'registros' => $dados['registros'] ?? [],
        ];
    }

    /** @param  array{visitas: list<int>, registros: list<int>}  $manifesto */
    private function salvarManifesto(array $manifesto): void
    {
        $path = storage_path('app/'.self::MANIFESTO);
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }

        file_put_contents($path, json_encode($manifesto));
    }
}

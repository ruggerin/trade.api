<?php

namespace App\Support;

use App\Enums\RecorrenciaAgendaVisita;
use App\Enums\UserType;
use App\Models\AgendaVisita;
use App\Models\Empresa;
use App\Models\PontoVenda;
use App\Models\Usuario;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * Rota de visitas em lote (docs/78 §4): a planilha de rota que a operação já usa vira o planejamento
 * de visitas. Cada dia marcado (X) é uma AgendaVisita SEMANAL do promotor naquela loja; o comando
 * diário `ordens-servico:gerar-por-agenda` transforma isso em compromisso, como já faz com a agenda
 * lançada pelo admin.
 *
 * - SUBSTITUI POR PAR LOJA + PROMOTOR: as regras SEMANAIS ativas desse promotor nessa loja ficam
 *   exatamente os dias marcados; o resto é desativado (nunca apagado). Linha sem nenhum dia desativa
 *   a rota do par. Outros promotores na loja e visitas avulsas não são tocados.
 * - Promotor pela coluna `promotor` (e-mail ou código externo) ou pelo promotor padrão da tela.
 * - Atribui o promotor à loja quando ainda não estava (senão a loja não aparece pra ele no app).
 * - Loja sem vendedor na carteira: aviso, a linha entra (decisão do dono do produto).
 * - Mesma loja + mesmo promotor em duas linhas: os dias somam.
 * - Tudo ou nada, com `simular`, como as outras importações.
 */
final class ImportacaoRotaVisitas
{
    public const MAX_LINHAS = 5000;

    /** dia_semana: 0 = domingo … 6 = sábado (mesma convenção de AgendaVisita). */
    public const DIAS = ['dom' => 0, 'seg' => 1, 'ter' => 2, 'qua' => 3, 'qui' => 4, 'sex' => 5, 'sab' => 6];

    public const COLUNAS = ['codigo_externo_loja', 'promotor', 'seg', 'ter', 'qua', 'qui', 'sex', 'sab', 'dom', 'horario'];

    private const MARCADO = ['x', 'sim', 's', '1'];

    /**
     * @return array<string, mixed>
     */
    public static function executar(Empresa $empresa, string $conteudo, bool $simular, ?string $promotorPadrao = null): array
    {
        $linhas = LeitorCsv::ler($conteudo, self::COLUNAS, ['codigo_externo_loja'], self::MAX_LINHAS, 'linha de rota');
        if (isset($linhas['erro'])) {
            return self::relatorio(0, 0, 0, 0, [['linha' => 1, 'codigo_externo' => null, 'mensagem' => $linhas['erro']]], [], false);
        }

        $lojas = PontoVenda::withoutGlobalScopes()->where('empresa_id', $empresa->id)
            ->whereIn('codigo_externo', array_filter(array_column(array_column($linhas, 'dados'), 'codigo_externo_loja')))
            ->get(['id', 'codigo_externo'])->groupBy('codigo_externo');
        $comVendedor = DB::table('vendedor_ponto_venda')->whereIn('ponto_venda_id', $lojas->flatten()->pluck('id'))
            ->distinct()->pluck('ponto_venda_id')->flip();
        $promotores = [];
        $resolverPromotor = function (string $chave) use ($empresa, &$promotores): Usuario|string {
            return $promotores[mb_strtolower($chave)] ??= self::promotor($empresa, $chave);
        };

        $padrao = null;
        if ($promotorPadrao !== null && trim($promotorPadrao) !== '') {
            $padrao = $resolverPromotor(trim($promotorPadrao));
            if (is_string($padrao)) {
                return self::relatorio(0, 0, 0, count($linhas), [['linha' => 0, 'codigo_externo' => null, 'mensagem' => "Promotor da rota: {$padrao}"]], [], false);
            }
        }

        $erros = [];
        $avisos = [];
        // "lojaId:usuarioId" => ['loja' => id, 'usuario' => id, 'dias' => [dia => horario|null]]
        $pares = [];

        foreach ($linhas as ['numero' => $numero, 'dados' => $dados]) {
            $codigoLoja = $dados['codigo_externo_loja'] ?? null;
            $mensagens = [];

            $loja = null;
            if (! $codigoLoja) {
                $mensagens[] = 'Código da loja vazio.';
            } elseif (! $lojas->has($codigoLoja)) {
                $mensagens[] = "Loja {$codigoLoja} não cadastrada.";
            } elseif ($lojas->get($codigoLoja)->count() > 1) {
                $mensagens[] = "Mais de uma loja com o código {$codigoLoja}: corrija o cadastro antes.";
            } else {
                $loja = $lojas->get($codigoLoja)->first();
            }

            $chavePromotor = isset($dados['promotor']) && trim($dados['promotor']) !== '' ? trim($dados['promotor']) : null;
            $promotor = $chavePromotor !== null ? $resolverPromotor($chavePromotor) : $padrao;
            if ($promotor === null) {
                $mensagens[] = 'Sem promotor: preencha a coluna promotor ou o "Promotor da rota" na tela.';
            } elseif (is_string($promotor)) {
                $mensagens[] = $promotor;
            }

            $horario = isset($dados['horario']) && trim($dados['horario']) !== '' ? trim($dados['horario']) : null;
            if ($horario !== null) {
                if (preg_match('/^(\d{1,2})[:h](\d{2})$/i', $horario, $m) && (int) $m[1] < 24 && (int) $m[2] < 60) {
                    $horario = sprintf('%02d:%02d', $m[1], $m[2]);
                } else {
                    $mensagens[] = "Horário \"{$horario}\" inválido: use HH:MM, como 08:30.";
                }
            }

            $dias = [];
            foreach (self::DIAS as $coluna => $dia) {
                $valor = isset($dados[$coluna]) ? mb_strtolower(trim($dados[$coluna])) : '';
                if ($valor === '') {
                    continue;
                }
                if (in_array($valor, self::MARCADO, true)) {
                    $dias[$dia] = $horario;
                } else {
                    $mensagens[] = mb_strtoupper($coluna)." \"{$dados[$coluna]}\" inválido: marque com X ou deixe vazio.";
                }
            }

            if ($mensagens) {
                $erros[] = ['linha' => $numero, 'codigo_externo' => $codigoLoja, 'mensagem' => implode(' ', $mensagens)];

                continue;
            }

            if (! $comVendedor->has($loja->id)) {
                $avisos[] = ['linha' => $numero, 'codigo_externo' => $codigoLoja, 'mensagem' => 'Loja sem vendedor na carteira.'];
            }

            $chave = "{$loja->id}:{$promotor->id}";
            $pares[$chave] ??= ['loja' => $loja->id, 'usuario' => $promotor->id, 'dias' => []];
            // Mesma loja + promotor em outra linha: os dias somam (horário da linha que marcou o dia).
            $pares[$chave]['dias'] = $dias + $pares[$chave]['dias'];
        }

        // O que muda, pra relatório e pra gravação.
        $criar = [];
        $atualizar = [];
        $desativar = [];
        $atribuir = [];
        foreach ($pares as $par) {
            $atuais = AgendaVisita::withoutGlobalScopes()
                ->where('empresa_id', $empresa->id)
                ->where('ponto_venda_id', $par['loja'])
                ->where('usuario_id', $par['usuario'])
                ->where('recorrencia', RecorrenciaAgendaVisita::SEMANAL)
                ->where('ativo', true)
                ->get();

            foreach ($par['dias'] as $dia => $horario) {
                $existente = $atuais->firstWhere('dia_semana', $dia);
                if (! $existente) {
                    $criar[] = ['loja' => $par['loja'], 'usuario' => $par['usuario'], 'dia' => $dia, 'horario' => $horario];
                } elseif ($horario !== null && substr((string) $existente->horario_previsto, 0, 5) !== $horario) {
                    $atualizar[] = [$existente, $horario];
                }
            }
            foreach ($atuais as $regra) {
                if (! array_key_exists($regra->dia_semana, $par['dias'])) {
                    $desativar[] = $regra->id;
                }
            }
            $atribuir[$par['loja']][] = $par['usuario'];
        }

        if ($erros || $simular) {
            return self::relatorio(count($criar), count($desativar), count($pares), count($linhas), $erros, $avisos, false);
        }

        $novas = [];
        DB::transaction(function () use ($empresa, $criar, $atualizar, $desativar, $atribuir, &$novas) {
            foreach ($criar as $c) {
                $novas[] = AgendaVisita::create([
                    'empresa_id' => $empresa->id,
                    'ponto_venda_id' => $c['loja'],
                    'usuario_id' => $c['usuario'],
                    'recorrencia' => RecorrenciaAgendaVisita::SEMANAL,
                    'dia_semana' => $c['dia'],
                    'horario_previsto' => $c['horario'],
                    // Mesmos padrões do cadastro pelo admin (AgendaVisitaController::store).
                    'prioridade' => 'MEDIA',
                    'obrigatoria' => true,
                    'ativo' => true,
                ]);
            }
            foreach ($atualizar as [$regra, $horario]) {
                $regra->update(['horario_previsto' => $horario]);
            }
            if ($desativar) {
                AgendaVisita::withoutGlobalScopes()->whereIn('id', $desativar)->update(['ativo' => false, 'updated_at' => now()]);
            }
            foreach ($atribuir as $lojaId => $usuarios) {
                PontoVenda::withoutGlobalScopes()->find($lojaId)?->promotores()->syncWithoutDetaching(array_unique($usuarios));
            }
        });

        // Regra nova do dia de hoje: gera a OS já, sem esperar o agendador (mesmo que o cadastro pelo admin faz).
        $hoje = now()->dayOfWeek;
        foreach ($novas as $agenda) {
            if ($agenda->dia_semana === $hoje) {
                Artisan::call('ordens-servico:gerar-por-agenda', ['agenda' => $agenda->uuid]);
            }
        }

        return self::relatorio(count($criar), count($desativar), count($pares), count($linhas), [], $avisos, true);
    }

    /** E-mail ou código externo → PROMOTOR ativo da empresa; string = motivo da recusa. */
    private static function promotor(Empresa $empresa, string $chave): Usuario|string
    {
        $usuario = Usuario::withoutGlobalScopes()
            ->where('empresa_id', $empresa->id)
            ->where(fn ($q) => $q->whereRaw('lower(email) = ?', [mb_strtolower($chave)])->orWhere('codigo_externo', $chave))
            ->get();

        if ($usuario->isEmpty()) {
            return "Promotor \"{$chave}\" não encontrado (use o e-mail ou o código externo do usuário).";
        }
        if ($usuario->count() > 1) {
            return "Mais de um usuário com \"{$chave}\": use o e-mail.";
        }
        $usuario = $usuario->first();
        if ($usuario->user_type !== UserType::PROMOTOR) {
            return "{$usuario->nome} não é promotor.";
        }
        if (! $usuario->ativo) {
            return "{$usuario->nome} está inativo.";
        }

        return $usuario;
    }

    /** @return array<string, mixed> */
    private static function relatorio(int $criadas, int $desativadas, int $pares, int $total, array $erros, array $avisos, bool $aplicado): array
    {
        return [
            'total' => $total,
            // `criadas` = regras de dia novas (o diálogo de importação já conta isso).
            'criadas' => $criadas,
            'agendas_desativadas' => $desativadas,
            'lojas' => $pares,
            'erros' => $erros,
            'avisos' => $avisos,
            'aplicado' => $aplicado,
        ];
    }
}

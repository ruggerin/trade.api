<?php

namespace App\Console\Commands;

use App\Enums\CheckoutTipo;
use App\Enums\StatusOrdemServico;
use App\Enums\TipoCampoRegistro;
use App\Models\Empresa;
use App\Models\ImagemRegistro;
use App\Models\OrdemServico;
use App\Models\ProdutoAuditoria;
use App\Models\TipoRegistro;
use App\Models\Usuario;
use App\Models\Visita;
use App\Models\VisitaRegistro;
use App\Support\CheckoutParametros;
use App\Support\FinalizacaoVisita;
use App\Support\Fuso;
use App\Support\OperacaoDoDia;
use App\Support\ResolverSortimentoCampo;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Simulador de atividade de campo pra servidor de teste/demonstração — os promotores das empresas
 * em `config('simulador.empresas')` (menos `simulador.excluir_emails`) "trabalham sozinhos" a
 * rota do dia (as OS com prazo hoje), dentro da jornada da empresa (JORNADA_INICIO/JORNADA_FIM):
 * check-in, registros dos formulários, check-out e posição de GPS, no horário certo.
 *
 * Agendado a cada 5 min (routes/console.php). Cada execução materializa só o que o "plano" do
 * promotor já alcançou até agora — o plano do dia é determinístico (semente = promotor + dia),
 * então toda execução recalcula o mesmo roteiro e só avança o que falta. Execução perdida se
 * recupera sozinha na próxima, com os horários originais.
 *
 * Só mexe no que ele mesmo criou: a visita simulada leva `idempotency_key` derivada da OS
 * (self::chaveVisita) — OS com visita real (o app de verdade) nunca é tocada.
 */
class SimularAtividade extends Command
{
    protected $signature = 'dev:simular-atividade';

    protected $description = 'Simula a rota do dia dos promotores das empresas de teste (config/simulador.php) — nunca usar em produção';

    /** Chance (%) de o promotor pular uma loja da rota — fica pendente/atrasada no painel. */
    private const CHANCE_PULAR = 8;

    /**
     * Chance (%) de o promotor esquecer de finalizar a ÚLTIMA visita do dia e ir embora — só nas
     * empresas com aviso/fechamento por saída detectada (docs/92). O GPS segue se afastando e quem
     * fecha é o `visitas:monitorar-saida` de verdade; se nada fechar, o simulador finaliza 50 min
     * depois do fim da jornada (o promotor que lembra no fim do dia).
     */
    private const CHANCE_ESQUECER = 25;

    /** Até onde o promotor que esqueceu se afasta da loja, em graus (~0,015° ≈ 1,6 km). */
    private const AFASTAMENTO_GRAUS = 0.015;

    private const OBSERVACOES_RUPTURA = [
        'Gôndola vazia, sem previsão do repositor.',
        'Sem estoque no depósito da loja.',
        'Produto fora da gôndola há pelo menos 2 dias, segundo o gerente.',
        'Concorrente ocupou o espaço da gôndola.',
        'Cliente reclamou que não encontra o produto há uma semana.',
        'Pedido atrasado, segundo o encarregado.',
    ];

    private const TEXTOS = [
        'Loja organizada, sem pendências.',
        'Gerente pediu mais material de ponto extra.',
        'Planograma seguido corretamente nesta visita.',
        'Concorrência com ação promocional forte esta semana.',
        'Reposição feita junto com o repositor da loja.',
        'Embalagem amassada no transporte.',
        'Só 1 caixa no depósito.',
        'Precificação divergente do tabloide.',
    ];

    public function handle(): int
    {
        $empresaIds = config('simulador.empresas');

        if ($empresaIds === []) {
            $this->info('Simulador desligado (SIMULADOR_ATIVIDADE_EMPRESAS vazio).');

            return self::SUCCESS;
        }

        foreach (Empresa::withoutGlobalScopes()->whereIn('id', $empresaIds)->get() as $empresa) {
            $this->simularEmpresa($empresa);
        }

        return self::SUCCESS;
    }

    private function simularEmpresa(Empresa $empresa): void
    {
        $fuso = Fuso::daEmpresa($empresa);
        $hoje = Fuso::hoje($fuso);
        $data = $hoje->toDateString();
        $jornada = OperacaoDoDia::jornada($empresa);
        $inicioJornada = Fuso::instanteLocal($data, $jornada['inicio'], $fuso);
        $fimJornada = Fuso::instanteLocal($data, $jornada['fim'], $fuso);
        $agora = now();

        // Fora da jornada não há o que fazer — a folga de 1h depois do fim fecha a última visita.
        if ($agora->lt($inicioJornada) || $agora->gt($fimJornada->copy()->addHour())) {
            return;
        }

        $promotores = Usuario::withoutGlobalScopes()
            ->where('empresa_id', $empresa->id)
            ->where('user_type', 'PROMOTOR')
            ->where('ativo', true)
            ->get()
            ->reject(fn (Usuario $u) => in_array(mb_strtolower($u->email), config('simulador.excluir_emails'), true));

        $contexto = [
            'tipos' => TipoRegistro::withoutGlobalScopes()->where('empresa_id', $empresa->id)->where('ativo', true)
                ->with(['campos', 'pontosVenda', 'redesLojas'])->get(),
            'produtos' => ProdutoAuditoria::withoutGlobalScopes()->where('empresa_id', $empresa->id)->get(),
            'data' => $data,
        ];

        $abertas = 0;
        $finalizadas = 0;
        // Só faz sentido esquecer de finalizar onde a saída detectada está ligada (docs/92).
        $permiteEsquecer = CheckoutParametros::avisoSaidaMinutos($empresa) > 0 || CheckoutParametros::automaticoMinutos($empresa) > 0;

        foreach ($promotores as $promotor) {
            $ordens = OrdemServico::withoutGlobalScopes()
                ->where('empresa_id', $empresa->id)
                ->where('usuario_id', $promotor->id)
                ->whereBetween('prazo_fim', Fuso::intervaloDoDia($data, $fuso))
                ->whereNotIn('status', [StatusOrdemServico::CANCELADA, StatusOrdemServico::AGUARDANDO_APROVACAO])
                ->with(['pontoVenda', 'visita'])
                ->orderByRaw('horario_previsto nulls last')
                ->orderBy('id')
                ->get();

            if ($ordens->isEmpty()) {
                continue;
            }

            $plano = $this->planoDoDia($promotor, $ordens, $hoje, $inicioJornada, $fimJornada, $permiteEsquecer);

            foreach ($plano as $etapa) {
                if ($etapa['inicio']->gt($agora)) {
                    break;
                }

                [$abriu, $fechou] = $this->executarEtapa($etapa, $agora, $contexto, $fimJornada);
                $abertas += $abriu;
                $finalizadas += $fechou;
            }

            $this->registrarPosicoes($empresa, $promotor, $plano, $inicioJornada, $fimJornada, $agora);
        }

        $this->info("Empresa {$empresa->id}: {$abertas} check-in(s), {$finalizadas} check-out(s).");
    }

    /**
     * Roteiro do dia do promotor: uma loja depois da outra, com deslocamento entre elas, nunca
     * antes de ~20 min do horário previsto da OS. Mesma semente = mesmo roteiro a cada execução.
     *
     * @return list<array{os: OrdemServico, inicio: Carbon, fim: Carbon}>
     */
    private function planoDoDia(Usuario $promotor, Collection $ordens, Carbon $hoje, Carbon $inicioJornada, Carbon $fimJornada, bool $permiteEsquecer = false): array
    {
        $rng = new Randomizer(new Mt19937(crc32("simulador|{$promotor->id}|{$hoje->toDateString()}")));
        $cursor = $inicioJornada->copy()->addMinutes($rng->getInt(5, 25));
        $plano = [];

        foreach ($ordens as $os) {
            if ($rng->getInt(1, 100) <= self::CHANCE_PULAR) {
                continue;
            }

            $inicio = $cursor->copy()->addMinutes($rng->getInt(10, 30));
            if ($os->horario_previsto) {
                $previsto = OperacaoDoDia::horarioPrevistoDaOs($os, $hoje->copy())->subMinutes(20);
                $inicio = $inicio->max($previsto);
            }
            if ($inicio->gte($fimJornada->copy()->subMinutes(15))) {
                break;
            }

            $fim = $inicio->copy()->addMinutes($rng->getInt(20, 50));
            $plano[] = ['os' => $os, 'inicio' => $inicio->utc(), 'fim' => $fim->utc(), 'esquece' => false];
            $cursor = $fim;
        }

        // Sorteado por último pra não mexer no roteiro de quem não esquece.
        if ($plano !== [] && $permiteEsquecer && $rng->getInt(1, 100) <= self::CHANCE_ESQUECER) {
            $plano[array_key_last($plano)]['esquece'] = true;
        }

        return $plano;
    }

    /** @return array{0: int, 1: int} [check-ins feitos, check-outs feitos] */
    private function executarEtapa(array $etapa, Carbon $agora, array $contexto, Carbon $fimJornada): array
    {
        /** @var OrdemServico $os */
        $os = $etapa['os'];
        $chave = self::chaveVisita($os);
        $abriu = 0;

        $visita = Visita::withoutGlobalScopes()->where('idempotency_key', $chave)->first();

        if (! $visita) {
            // OS que o app de verdade já pegou (ou concluiu) não é da conta do simulador.
            if ($os->status !== StatusOrdemServico::PENDENTE) {
                return [0, 0];
            }

            $pdv = $os->pontoVenda;
            $visita = Visita::create([
                'empresa_id' => $os->empresa_id,
                'ponto_venda_id' => $pdv->id,
                'usuario_id' => $os->usuario_id,
                'ordem_servico_id' => $os->id,
                'idempotency_key' => $chave,
                'status' => 'ABERTA',
                'inicio_data' => $etapa['inicio'],
                'inicio_latitude' => $this->perto($pdv->latitude),
                'inicio_longitude' => $this->perto($pdv->longitude),
                'inicio_distancia_metros' => random_int(3, 40),
                'checkin_recebido_em' => $etapa['inicio'],
            ]);
            $os->update(['status' => StatusOrdemServico::EM_ANDAMENTO, 'visita_id' => $visita->id]);
            $abriu = 1;
        }

        if ($visita->status->value !== 'ABERTA' || $etapa['fim']->gt($agora)) {
            return [$abriu, 0];
        }

        // Esqueceu de finalizar: fica aberta pro monitor de saída avisar/fechar. Rede de segurança:
        // 50 min depois do fim da jornada ainda aberta, o promotor "lembra" e finaliza (atrasado).
        $atrasado = false;
        if ($etapa['esquece']) {
            if ($agora->lt($fimJornada->copy()->addMinutes(50))) {
                return [$abriu, 0];
            }
            $atrasado = true;
        }

        DB::transaction(function () use ($visita, $os, $etapa, $contexto, $agora, $atrasado): void {
            $this->criarRegistros($visita, $os, $etapa, $contexto);

            // Mesmo caminho do check-out do app (raio, tempo confirmado por GPS, OS concluída,
            // régua de XP e mix da visita). Quem esqueceu finaliza longe da loja, na hora em que lembrou.
            $pdv = $os->pontoVenda;
            $fim = $atrasado ? $agora->copy() : $etapa['fim'];
            [$lat, $lng] = $atrasado
                ? [(float) $pdv->latitude + self::AFASTAMENTO_GRAUS, (float) $pdv->longitude + self::AFASTAMENTO_GRAUS]
                : [$this->perto($pdv->latitude), $this->perto($pdv->longitude)];
            FinalizacaoVisita::finalizar($visita, $fim, $lat, $lng, CheckoutTipo::PROMOTOR, ['checkout_recebido_em' => $fim]);
        });

        return [$abriu, 1];
    }

    /**
     * Formulários da visita: os obrigatórios que valem pra loja (SEMPRE, LOJA_REDE no escopo,
     * CONTRATO com contrato vigente) sempre; os livres por sorteio — ruptura de 0 a 3 produtos.
     */
    private function criarRegistros(Visita $visita, OrdemServico $os, array $etapa, array $contexto): void
    {
        $pdv = $os->pontoVenda;
        $comContrato = DB::table('contratos')->where('ponto_venda_id', $pdv->id)->where('ativo', true)
            ->where('vigencia_inicio', '<=', $etapa['inicio'])
            ->where(fn ($q) => $q->whereNull('vigencia_fim')->orWhere('vigencia_fim', '>=', $etapa['inicio']))
            ->exists();

        foreach ($contexto['tipos'] as $tipo) {
            $quantos = match (true) {
                (bool) $tipo->acao_obrigatoria => $this->obrigatorioValeNaLoja($tipo, $pdv, $comContrato) ? 1 : 0,
                (bool) $tipo->eh_ruptura => $this->sorteio([0 => 45, 1 => 30, 2 => 17, 3 => 8]),
                $tipo->exige_foto => random_int(1, 100) <= 45 ? 1 : 0,
                default => random_int(1, 100) <= 30 ? 1 : 0,
            };

            for ($i = 0; $i < $quantos; $i++) {
                $this->criarRegistro($visita, $tipo, $pdv, $etapa, $contexto);
            }
        }
    }

    private function criarRegistro(Visita $visita, TipoRegistro $tipo, $pdv, array $etapa, array $contexto): void
    {
        $momento = Carbon::createFromTimestamp(random_int(
            $etapa['inicio']->copy()->addMinutes(3)->timestamp,
            $etapa['fim']->copy()->subMinutes(2)->timestamp,
        ));
        $produto = ($tipo->eh_ruptura || $tipo->permite_vincular_catalogo) && $contexto['produtos']->isNotEmpty()
            ? $contexto['produtos']->random()
            : null;

        $valores = [];
        foreach ($tipo->campos as $campo) {
            $valor = $this->valorDoCampo($campo, $pdv);
            if ($valor !== null) {
                $valores[$campo->chave] = $valor;
            }
        }

        $registro = new VisitaRegistro([
            'visita_id' => $visita->id,
            'tipo_registro_id' => $tipo->id,
            'produto_auditoria_id' => $produto?->id,
            'tipo_vinculo' => $produto && ! $tipo->eh_ruptura ? 'PRODUTO' : null,
            'ruptura' => (bool) $tipo->eh_ruptura,
            'observacao' => $tipo->eh_ruptura
                ? self::OBSERVACOES_RUPTURA[array_rand(self::OBSERVACOES_RUPTURA)]
                : ($tipo->campos->isEmpty() ? self::TEXTOS[array_rand(self::TEXTOS)] : null),
            'valores_campos' => $valores ?: null,
        ]);
        $registro->created_at = $momento;
        $registro->updated_at = $momento;
        $registro->recebido_em = $momento;
        $registro->save();

        if ($tipo->exige_foto) {
            $this->anexarFotos($visita, $registro, $tipo);
        }
    }

    private function valorDoCampo($campo, $pdv): mixed
    {
        return match ($campo->tipo_campo) {
            TipoCampoRegistro::NUMERO => random_int(1, 12),
            TipoCampoRegistro::TEXTO => random_int(1, 100) <= 60 ? self::TEXTOS[array_rand(self::TEXTOS)] : null,
            TipoCampoRegistro::MOEDA => number_format(random_int(300, 2500) / 100, 2, '.', ''),
            TipoCampoRegistro::MULTIPLA_ESCOLHA => $campo->opcoes ? $campo->opcoes[array_rand($campo->opcoes)] : null,
            TipoCampoRegistro::BOOLEANO => random_int(1, 100) <= 80 ? '1' : '0',
            TipoCampoRegistro::DATA => now()->addDays(random_int(5, 40))->format('d/m/Y'),
            TipoCampoRegistro::SORTIMENTO => $this->sortimento($campo, $pdv),
            default => null,
        };
    }

    /** Mesmo formato que o app grava: JSON {presentes: [uuid], ausentes: [uuid]} — ~85% presente. */
    private function sortimento($campo, $pdv): ?string
    {
        $uuids = ResolverSortimentoCampo::resolver($campo, $pdv)->pluck('uuid');
        if ($uuids->isEmpty()) {
            return null;
        }

        [$presentes, $ausentes] = $uuids->partition(fn () => random_int(1, 100) <= 85);

        return json_encode(['presentes' => $presentes->values(), 'ausentes' => $ausentes->values()]);
    }

    /**
     * Reaproveita fotos reais já enviadas pra esse formulário (o arquivo nunca é apagado do
     * storage — cancelar registro é soft), cada uma numa linha nova de imagens_registro.
     */
    private function anexarFotos(Visita $visita, VisitaRegistro $registro, TipoRegistro $tipo): void
    {
        $caminhos = DB::table('imagens_registro')
            ->join('visita_registro_imagem', 'visita_registro_imagem.imagem_registro_id', '=', 'imagens_registro.id')
            ->join('visita_registros', 'visita_registros.id', '=', 'visita_registro_imagem.visita_registro_id')
            ->where('visita_registros.tipo_registro_id', $tipo->id)
            ->inRandomOrder()
            ->limit(random_int(1, 2))
            ->pluck('imagens_registro.caminho');

        foreach ($caminhos->values() as $ordem => $caminho) {
            $imagem = ImagemRegistro::create(['visita_id' => $visita->id, 'caminho' => $caminho]);
            $registro->imagens()->attach($imagem->id, ['ordem' => $ordem]);
        }
    }

    private function obrigatorioValeNaLoja(TipoRegistro $tipo, $pdv, bool $comContrato): bool
    {
        return match ($tipo->escopo_acao?->value) {
            'SEMPRE' => true,
            'CONTRATO' => $comContrato,
            'LOJA_REDE' => ($tipo->pontosVenda->isEmpty() && $tipo->redesLojas->isEmpty())
                || $tipo->pontosVenda->contains('id', $pdv->id)
                || ($pdv->rede_loja_id && $tipo->redesLojas->contains('id', $pdv->rede_loja_id)),
            // CAMPANHA só vale em visita de campanha — a rota da agenda não é.
            default => false,
        };
    }

    /**
     * GPS a cada 5 min desde o último ponto do dia: parado na loja durante a visita, andando em
     * linha reta entre uma loja e a próxima no deslocamento. Depois da última visita, para.
     */
    private function registrarPosicoes(Empresa $empresa, Usuario $promotor, array $plano, Carbon $inicioJornada, Carbon $fimJornada, Carbon $agora): void
    {
        if ($plano === []) {
            return;
        }

        $ultimo = DB::table('localizacoes_historico')->where('usuario_id', $promotor->id)
            ->where('capturado_em', '>=', $inicioJornada)->max('capturado_em');
        $instante = $ultimo ? Carbon::parse($ultimo)->addMinutes(5) : $plano[0]['inicio']->copy()->subMinutes(15);
        // Quem esqueceu de finalizar segue com o GPS ligado depois da última loja (é o que deixa o
        // monitor de saída enxergar o afastamento); os outros param no fim da última visita.
        $ultima = end($plano);
        $limite = $agora->copy()->min($ultima['esquece'] ? $fimJornada->copy()->addMinutes(50) : $ultima['fim']);

        $pontos = [];
        for (; $instante->lte($limite); $instante->addMinutes(5)) {
            [$lat, $lng] = $this->posicaoEm($plano, $instante);
            $pontos[] = [
                'empresa_id' => $empresa->id,
                'usuario_id' => $promotor->id,
                'latitude' => $lat,
                'longitude' => $lng,
                'capturado_em' => $instante->copy(),
            ];
        }

        if ($pontos === []) {
            return;
        }

        DB::table('localizacoes_historico')->insertOrIgnore($pontos);
        $ultimoPonto = end($pontos);
        $promotor->timestamps = false;
        $promotor->forceFill([
            'ultima_localizacao_latitude' => $ultimoPonto['latitude'],
            'ultima_localizacao_longitude' => $ultimoPonto['longitude'],
            'ultima_localizacao_em' => $ultimoPonto['capturado_em'],
        ])->save();
    }

    /** @return array{0: float, 1: float} */
    private function posicaoEm(array $plano, Carbon $instante): array
    {
        $anterior = null;
        foreach ($plano as $etapa) {
            $pdv = $etapa['os']->pontoVenda;
            if ($instante->lt($etapa['inicio'])) {
                if (! $anterior) {
                    return [$this->perto($pdv->latitude), $this->perto($pdv->longitude)];
                }
                $origem = $anterior['os']->pontoVenda;
                $total = max(1, $anterior['fim']->diffInSeconds($etapa['inicio']));
                $t = min(1, max(0, $anterior['fim']->diffInSeconds($instante) / $total));

                return [
                    (float) $origem->latitude + ((float) $pdv->latitude - (float) $origem->latitude) * $t,
                    (float) $origem->longitude + ((float) $pdv->longitude - (float) $origem->longitude) * $t,
                ];
            }
            if ($instante->lte($etapa['fim'])) {
                return [$this->perto($pdv->latitude), $this->perto($pdv->longitude)];
            }
            $anterior = $etapa;
        }
        $ultima = end($plano);
        $pdv = $ultima['os']->pontoVenda;
        if ($ultima['esquece']) {
            // Indo embora: em ~30 min chega a AFASTAMENTO_GRAUS da loja e fica por lá.
            $t = min(1, $ultima['fim']->diffInMinutes($instante, true) / 30);

            return [
                $this->perto((float) $pdv->latitude + self::AFASTAMENTO_GRAUS * $t),
                $this->perto((float) $pdv->longitude + self::AFASTAMENTO_GRAUS * $t),
            ];
        }

        return [$this->perto($pdv->latitude), $this->perto($pdv->longitude)];
    }

    /** Uns poucos metros de ruído (~0,0002° ≈ 20 m) — GPS de verdade nunca cai cravado na loja. */
    private function perto($coordenada): float
    {
        return round((float) $coordenada + random_int(-20, 20) / 100000, 7);
    }

    /** @param  array<int, int>  $pesos  valor => peso */
    private function sorteio(array $pesos): int
    {
        $alvo = random_int(1, array_sum($pesos));
        foreach ($pesos as $valor => $peso) {
            if (($alvo -= $peso) <= 0) {
                return $valor;
            }
        }

        return array_key_first($pesos);
    }

    /** UUID fixo por OS — marca a visita como do simulador e evita check-in duplicado. */
    public static function chaveVisita(OrdemServico $os): string
    {
        $hash = md5("simulador-visita-{$os->id}");

        return sprintf('%s-%s-4%s-a%s-%s', substr($hash, 0, 8), substr($hash, 8, 4), substr($hash, 13, 3), substr($hash, 17, 3), substr($hash, 20, 12));
    }
}

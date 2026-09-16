<?php

namespace App\Console\Commands;

use App\Enums\OrigemOrdemServico;
use App\Enums\StatusOrdemServico;
use App\Models\Direcionamento;
use App\Models\Empresa;
use App\Models\OrdemServico;
use App\Models\PontoVenda;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Geração em massa de OrdemServico a partir de Direcionamento — uma por PDV elegível, cada uma
 * já com os formulários exigidos copiados. Ver docs/25-DIRECIONAMENTO-ORDEM-SERVICO.md §4.
 *
 * Disparado de duas formas: síncrona (DirecionamentoController::store/update passa o uuid, só
 * gera pra aquele Direcionamento na hora — "Dia dos Pais" não espera o cron do dia seguinte) e
 * diária via agendador (sem argumento, roda pra todos — cobre PDV/promotor que virou elegível
 * durante a vigência). Nunca duplica: pula o par Direcionamento+PDV se já existe OS
 * PENDENTE/EM_ANDAMENTO pra ele, mesmo critério de GerarOrdensServicoPorCampanha.
 */
class GerarOrdensServicoPorDirecionamento extends Command
{
    protected $signature = 'ordens-servico:gerar-por-direcionamento {direcionamento? : uuid do Direcionamento — sem isso, roda para todos os ativos vigentes}';

    protected $description = 'Gera OrdemServico pendente por PDV elegível para Direcionamentos ativos vigentes, copiando os formulários exigidos';

    public function handle(): int
    {
        $totalCriadas = 0;
        $uuidFiltro = $this->argument('direcionamento');

        Empresa::withoutGlobalScopes()->where('ativo', true)->each(function (Empresa $empresa) use (&$totalCriadas, $uuidFiltro): void {
            $direcionamentos = Direcionamento::withoutGlobalScopes()
                ->where('empresa_id', $empresa->id)
                ->where('ativo', true)
                ->where('vigencia_inicio', '<=', now())
                ->where('vigencia_fim', '>=', now())
                ->when($uuidFiltro, fn ($q) => $q->where('uuid', $uuidFiltro))
                ->with(['formularios', 'pontosVenda', 'redesLoja', 'promotores'])
                ->get();

            if ($direcionamentos->isEmpty()) {
                return;
            }

            $pontosVenda = PontoVenda::withoutGlobalScopes()
                ->where('empresa_id', $empresa->id)
                ->where('ativo', true)
                ->with('promotores')
                ->get();

            foreach ($direcionamentos as $direcionamento) {
                $elegiveis = $this->resolverPdvsElegiveis($pontosVenda, $direcionamento);

                foreach ($elegiveis as $pontoVenda) {
                    if ($this->criarSeDevido($empresa, $direcionamento, $pontoVenda)) {
                        $totalCriadas++;
                    }
                }
            }
        });

        $this->info("Ordens de serviço criadas: {$totalCriadas}.");

        return self::SUCCESS;
    }

    /**
     * E entre categorias diferentes (loja/rede/promotor), OU dentro da mesma categoria — ver
     * docs/25 §2 decisão 2. Sem filtro nenhum marcado nas 3 categorias = vale pra empresa
     * inteira (todo PDV ativo).
     */
    private function resolverPdvsElegiveis(Collection $pontosVendaAtivos, Direcionamento $direcionamento): Collection
    {
        $filtroPontosVenda = $direcionamento->pontosVenda->pluck('id');
        $filtroRedes = $direcionamento->redesLoja->pluck('id');
        $filtroPromotores = $direcionamento->promotores->pluck('id');

        if ($filtroPontosVenda->isEmpty() && $filtroRedes->isEmpty() && $filtroPromotores->isEmpty()) {
            return $pontosVendaAtivos;
        }

        return $pontosVendaAtivos->filter(function (PontoVenda $pdv) use ($filtroPontosVenda, $filtroRedes, $filtroPromotores) {
            if ($filtroPontosVenda->isNotEmpty() && ! $filtroPontosVenda->contains($pdv->id)) {
                return false;
            }
            if ($filtroRedes->isNotEmpty() && ! $filtroRedes->contains($pdv->rede_loja_id)) {
                return false;
            }
            if ($filtroPromotores->isNotEmpty() && $pdv->promotores->pluck('id')->intersect($filtroPromotores)->isEmpty()) {
                return false;
            }

            return true;
        })->values();
    }

    private function criarSeDevido(Empresa $empresa, Direcionamento $direcionamento, PontoVenda $pontoVenda): bool
    {
        $jaExiste = OrdemServico::withoutGlobalScopes()
            ->where('direcionamento_id', $direcionamento->id)
            ->where('ponto_venda_id', $pontoVenda->id)
            ->whereIn('status', [StatusOrdemServico::PENDENTE, StatusOrdemServico::EM_ANDAMENTO])
            ->exists();

        if ($jaExiste) {
            return false;
        }

        $ordemServico = OrdemServico::create([
            'empresa_id' => $empresa->id,
            'ponto_venda_id' => $pontoVenda->id,
            'usuario_id' => $this->resolverUsuarioId($pontoVenda, $direcionamento->promotores->pluck('id')),
            'origem' => OrigemOrdemServico::DIRECIONAMENTO,
            'direcionamento_id' => $direcionamento->id,
            'obrigatoria' => true,
            'prazo_inicio' => now(),
            'prazo_fim' => $direcionamento->vigencia_fim,
            'status' => StatusOrdemServico::PENDENTE,
        ]);

        foreach ($direcionamento->formularios as $formulario) {
            $ordemServico->formularios()->attach($formulario->id, [
                'obrigatorio' => $formulario->pivot->obrigatorio,
                'calcula_percentual_compliance' => $formulario->pivot->calcula_percentual_compliance,
            ]);
        }

        return true;
    }

    /**
     * Filtro de promotor vazio: PDV com exatamente 1 promotor atribuído direciona a ele, senão
     * fila aberta. Filtro de promotor preenchido: restringe aos candidatos que batem com o
     * filtro primeiro — PDV pode ter vários promotores no total, mas só 1 deles dentro do
     * filtro, e nesse caso ainda dá pra direcionar. Mesmo raciocínio de "na dúvida, não chuta"
     * de VisitaController::resolverCampanhaUnica.
     */
    private function resolverUsuarioId(PontoVenda $pontoVenda, Collection $filtroPromotores): ?int
    {
        $candidatos = $filtroPromotores->isNotEmpty()
            ? $pontoVenda->promotores->pluck('id')->intersect($filtroPromotores)
            : $pontoVenda->promotores->pluck('id');

        return $candidatos->count() === 1 ? $candidatos->first() : null;
    }
}

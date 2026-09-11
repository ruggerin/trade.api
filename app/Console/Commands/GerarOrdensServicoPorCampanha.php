<?php

namespace App\Console\Commands;

use App\Enums\OrigemOrdemServico;
use App\Enums\StatusOrdemServico;
use App\Models\CampanhaAuditoria;
use App\Models\Empresa;
use App\Models\OrdemServico;
use App\Models\PontoVenda;
use Illuminate\Console\Command;

/**
 * Geração automática de OrdemServico a partir de campanhas recorrentes — a peça que
 * docs/07-ORDEM-DE-SERVICO.md deixou como "não implementado". Rodado periodicamente (ver
 * routes/console.php), varre toda empresa ativa e, pra cada campanha `ativo` + `
 * execucao_recorrente` + `frequencia_dias` definido + dentro da vigência, garante uma OS
 * `PENDENTE` por PDV ativo — nunca duplica enquanto já existir uma `PENDENTE`/`EM_ANDAMENTO`
 * pro mesmo par campanha+PDV, e só abre um novo ciclo depois que `frequencia_dias` já passou
 * desde o `prazo_fim` do ciclo anterior (concluído ou cancelado).
 *
 * Campanha não tem vínculo direto com PDV no schema (ver CampanhaAuditoriaController::disponiveis
 * — o algoritmo de produtos-a-auditar já é o mesmo pra qualquer PDV ativo da empresa), então
 * "vigente pra quais PDVs" aqui significa "todo PDV ativo da empresa", sem distinção.
 */
class GerarOrdensServicoPorCampanha extends Command
{
    protected $signature = 'ordens-servico:gerar-por-campanha';

    protected $description = 'Gera OrdemServico pendente por PDV para campanhas recorrentes vigentes, respeitando a frequência de cada uma';

    public function handle(): int
    {
        $totalCriadas = 0;

        Empresa::withoutGlobalScopes()->where('ativo', true)->each(function (Empresa $empresa) use (&$totalCriadas): void {
            $campanhas = CampanhaAuditoria::withoutGlobalScopes()
                ->where('empresa_id', $empresa->id)
                ->where('ativo', true)
                ->where('execucao_recorrente', true)
                ->whereNotNull('frequencia_dias')
                ->where('vigencia_inicio', '<=', now())
                ->where('vigencia_fim', '>=', now())
                ->get();

            if ($campanhas->isEmpty()) {
                return;
            }

            $pontosVenda = PontoVenda::withoutGlobalScopes()
                ->where('empresa_id', $empresa->id)
                ->where('ativo', true)
                ->with('promotores')
                ->get();

            foreach ($campanhas as $campanha) {
                foreach ($pontosVenda as $pontoVenda) {
                    if ($this->criarSeDevido($empresa, $campanha, $pontoVenda)) {
                        $totalCriadas++;
                    }
                }
            }
        });

        $this->info("Ordens de serviço criadas: {$totalCriadas}.");

        return self::SUCCESS;
    }

    private function criarSeDevido(Empresa $empresa, CampanhaAuditoria $campanha, PontoVenda $pontoVenda): bool
    {
        $temAberta = OrdemServico::withoutGlobalScopes()
            ->where('campanha_id', $campanha->id)
            ->where('ponto_venda_id', $pontoVenda->id)
            ->whereIn('status', [StatusOrdemServico::PENDENTE, StatusOrdemServico::EM_ANDAMENTO])
            ->exists();

        if ($temAberta) {
            return false;
        }

        $ultima = OrdemServico::withoutGlobalScopes()
            ->where('campanha_id', $campanha->id)
            ->where('ponto_venda_id', $pontoVenda->id)
            ->latest('prazo_fim')
            ->first();

        if ($ultima && $ultima->prazo_fim->copy()->addDays($campanha->frequencia_dias)->isFuture()) {
            return false;
        }

        // PDV com exatamente um promotor atribuído: direciona a ele. Zero ou mais de um —
        // fila aberta, mesmo raciocínio de VisitaController::resolverCampanhaUnica (na dúvida,
        // não chuta).
        $usuarioId = $pontoVenda->promotores->count() === 1 ? $pontoVenda->promotores->first()->id : null;

        $prazoFim = now()->addDays($campanha->frequencia_dias)->min($campanha->vigencia_fim);

        OrdemServico::create([
            'empresa_id' => $empresa->id,
            'ponto_venda_id' => $pontoVenda->id,
            'usuario_id' => $usuarioId,
            'origem' => OrigemOrdemServico::CAMPANHA,
            'campanha_id' => $campanha->id,
            'obrigatoria' => true,
            'prazo_inicio' => now(),
            'prazo_fim' => $prazoFim,
            'status' => StatusOrdemServico::PENDENTE,
        ]);

        return true;
    }
}

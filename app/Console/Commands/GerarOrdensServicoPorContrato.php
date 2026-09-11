<?php

namespace App\Console\Commands;

use App\Enums\OrigemOrdemServico;
use App\Enums\PrioridadeVisita;
use App\Enums\StatusOrdemServico;
use App\Models\Contrato;
use App\Models\Empresa;
use App\Models\OrdemServico;
use App\Support\AvisoVencimentoContrato;
use Illuminate\Console\Command;

/**
 * Geração automática de OrdemServico a partir de Contrato (comodato/ponto extra) vencendo —
 * mesmo desenho de GerarOrdensServicoPorCampanha/GerarOrdensServicoPorAgenda. Pra cada Contrato
 * `ativo` cuja `vigencia_fim` já esteja dentro da janela de aviso da empresa (parametro
 * `CONTRATO_AVISO_DIAS`, ver App\Support\AvisoVencimentoContrato), garante uma OS `PENDENTE` —
 * nunca duplica enquanto já existir uma `PENDENTE`/`EM_ANDAMENTO` pro mesmo contrato. Ver
 * docs/07-ORDEM-DE-SERVICO.md §5.
 *
 * Sem limite inferior de data: um contrato que já venceu sem ninguém notar também gera aviso —
 * atrasado é melhor que nunca. Simplificação aceita conscientemente: se a OS gerada for
 * concluída/cancelada mas ninguém atualizar `vigencia_fim` do contrato (ex.: renovação feita só
 * de boca, sem editar o cadastro), a próxima passada do comando gera outra OS de aviso — não
 * existe cooldown aqui (diferente de campanha, que tem `frequencia_dias`), porque vencimento de
 * contrato não é recorrente por natureza, é um evento único que só some quando alguém edita o
 * contrato de verdade.
 */
class GerarOrdensServicoPorContrato extends Command
{
    protected $signature = 'ordens-servico:gerar-por-contrato';

    protected $description = 'Gera OrdemServico pendente para contratos (comodato/ponto extra) vencendo dentro da janela de aviso da empresa';

    public function handle(): int
    {
        $totalCriadas = 0;

        Empresa::withoutGlobalScopes()->where('ativo', true)->each(function (Empresa $empresa) use (&$totalCriadas): void {
            $limite = now()->addDays(AvisoVencimentoContrato::dias($empresa));

            $contratos = Contrato::withoutGlobalScopes()
                ->where('empresa_id', $empresa->id)
                ->where('ativo', true)
                ->where('vigencia_fim', '<=', $limite)
                ->with('pontoVenda.promotores')
                ->get();

            foreach ($contratos as $contrato) {
                if ($this->criarSeDevido($empresa, $contrato)) {
                    $totalCriadas++;
                }
            }
        });

        $this->info("Ordens de serviço criadas: {$totalCriadas}.");

        return self::SUCCESS;
    }

    private function criarSeDevido(Empresa $empresa, Contrato $contrato): bool
    {
        $temAberta = OrdemServico::withoutGlobalScopes()
            ->where('contrato_id', $contrato->id)
            ->whereIn('status', [StatusOrdemServico::PENDENTE, StatusOrdemServico::EM_ANDAMENTO])
            ->exists();

        if ($temAberta) {
            return false;
        }

        $pontoVenda = $contrato->pontoVenda;
        // PDV com exatamente um promotor atribuído: direciona a ele. Zero ou mais de um — fila
        // aberta, mesmo raciocínio de GerarOrdensServicoPorCampanha::criarSeDevido.
        $usuarioId = $pontoVenda->promotores->count() === 1 ? $pontoVenda->promotores->first()->id : null;

        OrdemServico::create([
            'empresa_id' => $empresa->id,
            'ponto_venda_id' => $contrato->ponto_venda_id,
            'usuario_id' => $usuarioId,
            'origem' => OrigemOrdemServico::CONTRATO,
            'contrato_id' => $contrato->id,
            'prioridade' => PrioridadeVisita::ALTA,
            'obrigatoria' => true,
            'prazo_inicio' => now(),
            'prazo_fim' => $contrato->vigencia_fim,
            'status' => StatusOrdemServico::PENDENTE,
            'observacao' => sprintf(
                'Contrato de %s vence em %s — verificar renovação.',
                $contrato->tipo->value,
                $contrato->vigencia_fim->format('d/m/Y'),
            ),
        ]);

        return true;
    }
}

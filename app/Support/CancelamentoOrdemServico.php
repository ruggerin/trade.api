<?php

namespace App\Support;

use App\Enums\ResponsavelNaoExecucao;
use App\Enums\StatusOrdemServico;
use App\Models\MotivoNaoExecucao;
use App\Models\OrdemServico;
use App\Models\OrdemServicoHistorico;
use App\Models\Usuario;

/**
 * Único lugar que cancela uma OrdemServico com rastro (docs/59 §3.3): grava autor, data, motivo e
 * "quem causou" na própria OS e uma linha no histórico append-only. Antes, cancelar era só
 * `update(['status' => CANCELADA])` — sem autor, sem motivo, e a OS sumia do relatório sem marca.
 */
final class CancelamentoOrdemServico
{
    public static function aplicar(
        OrdemServico $os,
        Usuario $por,
        ?ResponsavelNaoExecucao $responsavel,
        ?string $motivoUuid = null,
        ?string $motivoTexto = null,
        string $acao = 'CANCELADA',
    ): void {
        $motivoId = $motivoUuid ? MotivoNaoExecucao::where('uuid', $motivoUuid)->value('id') : null;
        $motivoTexto = $motivoTexto !== null && trim($motivoTexto) !== '' ? trim($motivoTexto) : null;

        $os->update([
            'status' => StatusOrdemServico::CANCELADA,
            'cancelada_em' => now(),
            'cancelada_por_id' => $por->id,
            'motivo_cancelamento_id' => $motivoId,
            'motivo_cancelamento_texto' => $motivoTexto,
            'responsavel_nao_execucao' => $responsavel?->value,
        ]);

        self::registrar($os, $por, $acao, [
            'responsavel_nao_execucao' => $responsavel?->value,
            'motivo_id' => $motivoId,
            'motivo_texto' => $motivoTexto,
        ]);
    }

    /** Linha no histórico append-only — também usada pelos fluxos de aprovação/rejeição. */
    public static function registrar(OrdemServico $os, ?Usuario $por, string $acao, array $dados = []): void
    {
        OrdemServicoHistorico::create([
            'ordem_servico_id' => $os->id,
            'usuario_id' => $por?->id,
            'acao' => $acao,
            'dados' => $dados ?: null,
        ]);
    }
}

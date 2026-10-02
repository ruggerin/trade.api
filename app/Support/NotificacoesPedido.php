<?php

namespace App\Support;

use App\Models\Empresa;
use App\Models\Pedido;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Aviso ao promotor da loja sobre pedido do ERP — docs/54-DETALHE-PEDIDO-PREVISAO-E-NOTIFICACAO.md
 * §5 Fase 3. Destinatários: os promotores atribuídos à loja (`promotor_pontos_venda`).
 */
final class NotificacoesPedido
{
    public const PREVISTO = 'PREVISTO';

    public const ENTREGUE = 'ENTREGUE';

    /** Status calculado na leitura, nunca gravado (§4). */
    public const STATUS_A_CAMINHO = 'A_CAMINHO';

    public const STATUS_ATRASADO = 'ATRASADO';

    public const STATUS_ENTREGUE = 'ENTREGUE';

    /** Avisa todos os promotores da loja do pedido (um registro por promotor). */
    public static function avisar(Pedido $pedido, string $tipo): void
    {
        $promotores = DB::table('promotor_pontos_venda')
            ->where('ponto_venda_id', $pedido->ponto_venda_id)
            ->pluck('usuario_id');

        $agora = now();
        DB::table('notificacoes_pedido')->insert($promotores->map(fn ($usuarioId) => [
            'usuario_id' => $usuarioId,
            'pedido_id' => $pedido->id,
            'tipo' => $tipo,
            'data_previsao' => $pedido->data_previsao_entrega?->toDateString(),
            'created_at' => $agora,
        ])->all());
    }

    /**
     * Entregue se tem entrega; senão a caminho — ou atrasado, se a previsão já passou (no dia da
     * empresa, docs/50 §4.3). Sem previsão (ERP ainda não manda) = a caminho.
     */
    public static function status(Pedido $pedido, ?Carbon $hoje = null): string
    {
        if ($pedido->entregas->isNotEmpty()) {
            return self::STATUS_ENTREGUE;
        }

        $hoje ??= Fuso::hoje(Fuso::daEmpresa(Empresa::find($pedido->empresa_id)));
        $previsao = $pedido->data_previsao_entrega;

        return $previsao !== null && $previsao->toDateString() < $hoje->toDateString()
            ? self::STATUS_ATRASADO
            : self::STATUS_A_CAMINHO;
    }
}

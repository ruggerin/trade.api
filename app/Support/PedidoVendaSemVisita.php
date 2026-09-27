<?php

namespace App\Support;

use App\Models\Empresa;
use App\Models\Parametro;

/**
 * Se o vendedor (PROMOTOR com pedidos_venda.criar) pode tirar Pedido de Venda fora de uma visita
 * — ex.: cliente ligou e ele já anota o pedido sem estar na loja. Configurável por empresa via
 * `Parametro` `PEDIDO_VENDA_SEM_VISITA_PERMITIDO`, mesmo padrão de CancelamentoVisita.
 * Ausente/inativo = `false`: o pedido só nasce dentro de uma visita em andamento (o "pedido no
 * contexto da visita" é o motivo da feature, docs/38-PEDIDO-VENDEDOR.md §2). Não vale pra
 * ADMIN/GESTOR, que não fazem visita.
 */
class PedidoVendaSemVisita
{
    private const VALORES_VERDADEIROS = ['1', 'true', 'sim', 'yes'];

    public static function permitido(Empresa $empresa): bool
    {
        $parametro = Parametro::query()
            ->where('empresa_id', $empresa->id)
            ->where('chave', 'PEDIDO_VENDA_SEM_VISITA_PERMITIDO')
            ->where('ativo', true)
            ->first();

        if (! $parametro) {
            return false;
        }

        return in_array(strtolower((string) $parametro->valor), self::VALORES_VERDADEIROS, true);
    }
}

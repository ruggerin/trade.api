<?php

namespace App\Http\Requests\Pedido;

use App\Support\PedidosErp;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePedidoRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Permissão já validada pelo middleware 'permissao:pedidos.gerenciar'.
        return true;
    }

    public function rules(): array
    {
        return [
            'ponto_venda_uuid' => [
                'required', 'string',
                Rule::exists('pontos_venda', 'uuid')->where('empresa_id', $this->user()->empresa_id),
            ],
            // Pedido, itens, valor e faturamento: as mesmas regras da API de integração (docs/96).
            ...PedidosErp::regras(),
        ];
    }
}

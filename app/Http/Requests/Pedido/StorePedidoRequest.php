<?php

namespace App\Http\Requests\Pedido;

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
            'numero_pedido' => ['required', 'string', 'max:100'],
            'numero_nf' => ['nullable', 'string', 'max:100'],
            'data_pedido' => ['required', 'date'],
            'observacao' => ['nullable', 'string', 'max:2000'],
            'itens' => ['required', 'array', 'min:1', 'max:500'],
            'itens.*.codigo_externo_produto' => ['required', 'string', 'max:100'],
            'itens.*.descricao_produto' => ['required', 'string', 'max:255'],
            'itens.*.quantidade' => ['required', 'numeric', 'gt:0'],
        ];
    }
}

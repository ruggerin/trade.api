<?php

namespace App\Http\Requests\PedidoVenda;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Criar e editar usam o mesmo corpo: a lista de itens é sempre enviada inteira (substitui a
 * anterior), mais simples que PATCH item a item pra uma grade que o vendedor monta na tela.
 * Permissão/ownership/status são checados no controller (PROMOTOR não passa pelo
 * EnsurePermissao, ver App\Support\PermissaoPedidoVenda).
 */
class SalvarPedidoVendaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $empresaId = $this->user()->empresa_id;
        $criando = $this->isMethod('post');

        return [
            // A loja não muda depois de criado — pedido pra outra loja é outro pedido.
            'ponto_venda_uuid' => [
                Rule::requiredIf($criando), Rule::prohibitedIf(! $criando), 'string',
                Rule::exists('pontos_venda', 'uuid')->where('empresa_id', $empresaId),
            ],
            'visita_uuid' => [
                'nullable', 'string', Rule::prohibitedIf(! $criando),
                Rule::exists('visitas', 'uuid')->where('empresa_id', $empresaId),
            ],
            'observacao' => ['nullable', 'string', 'max:2000'],
            'itens' => ['required', 'array', 'min:1', 'max:200'],
            // Produto repetido na mesma grade é quase sempre toque duplo — soma a quantidade na
            // linha existente em vez de duas linhas.
            'itens.*.produto_uuid' => ['required', 'string', 'distinct'],
            'itens.*.quantidade' => ['required', 'numeric', 'gt:0', 'max:999999'],
            'itens.*.preco' => ['required', 'numeric', 'gt:0', 'max:9999999'],
        ];
    }

    public function messages(): array
    {
        return [
            'itens.required' => 'Adicione pelo menos um produto ao pedido.',
            'itens.min' => 'Adicione pelo menos um produto ao pedido.',
            'itens.*.produto_uuid.distinct' => 'Produto repetido no pedido — ajuste a quantidade na linha existente.',
            'itens.*.quantidade.gt' => 'A quantidade precisa ser maior que zero.',
            'itens.*.preco.gt' => 'O preço precisa ser maior que zero.',
        ];
    }
}

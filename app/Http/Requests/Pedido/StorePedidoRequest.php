<?php

namespace App\Http\Requests\Pedido;

use App\Enums\StatusFaturamento;
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
            // Previsão de chegada na loja (docs/54 §4) — opcional: pedido antigo/ERP que ainda não
            // manda. Reenviar o pedido com outra data = revisão da previsão (avisa o promotor de novo).
            'data_previsao_entrega' => ['nullable', 'date'],
            'observacao' => ['nullable', 'string', 'max:2000'],
            'itens' => ['required', 'array', 'min:1', 'max:500'],
            'itens.*.codigo_externo_produto' => ['required', 'string', 'max:100'],
            'itens.*.descricao_produto' => ['required', 'string', 'max:255'],
            'itens.*.quantidade' => ['required', 'numeric', 'gt:0'],

            // Valor e faturamento (docs/61 §5.1) — todos opcionais: o ERP que ainda não manda
            // continua funcionando. Devolução chega com valor NEGATIVO (Winthor), por isso sem `min`.
            // Limites = o que as colunas decimal(14,2)/(14,4) aguentam; fora disso seria 500 do banco.
            'valor_total' => ['nullable', 'numeric', 'between:-999999999999.99,999999999999.99'],
            'valor_lucro_total' => ['nullable', 'numeric', 'between:-999999999999.99,999999999999.99'],
            'status_faturamento' => ['nullable', Rule::enum(StatusFaturamento::class)],
            'faturado_em' => ['nullable', 'date'],
            'itens.*.preco_unitario' => ['nullable', 'numeric', 'between:-9999999999.9999,9999999999.9999'],
            'itens.*.subtotal' => ['nullable', 'numeric', 'between:-999999999999.99,999999999999.99'],
            'itens.*.custo_unitario' => ['nullable', 'numeric', 'between:-9999999999.9999,9999999999.9999'],
            'itens.*.valor_lucro' => ['nullable', 'numeric', 'between:-999999999999.99,999999999999.99'],
        ];
    }
}

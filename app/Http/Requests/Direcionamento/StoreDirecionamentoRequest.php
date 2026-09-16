<?php

namespace App\Http\Requests\Direcionamento;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDirecionamentoRequest extends FormRequest
{
    public function authorize(): bool
    {
        // user_type/permissão já validados pelo middleware 'permissao:ordens_servico.gerenciar'.
        return true;
    }

    public function rules(): array
    {
        $empresaId = $this->user()->empresa_id;

        return [
            'descricao' => ['required', 'string', 'max:255'],
            'vigencia_inicio' => ['required', 'date'],
            'vigencia_fim' => ['required', 'date', 'after_or_equal:vigencia_inicio'],

            // Filtros multi-escolha, todos opcionais — ausência de qualquer um dos três =
            // Direcionamento vale pra empresa inteira. Ver docs/25 §2 decisão 2.
            'ponto_venda_uuids' => ['nullable', 'array'],
            'ponto_venda_uuids.*' => [
                'string', Rule::exists('pontos_venda', 'uuid')->where('empresa_id', $empresaId),
            ],
            'rede_loja_uuids' => ['nullable', 'array'],
            'rede_loja_uuids.*' => [
                'string', Rule::exists('redes_lojas', 'uuid')->where('empresa_id', $empresaId),
            ],
            'promotor_uuids' => ['nullable', 'array'],
            'promotor_uuids.*' => [
                'string',
                Rule::exists('usuarios', 'uuid')->where('empresa_id', $empresaId)->where('user_type', 'PROMOTOR'),
            ],

            // Ao menos 1 formulário exigido — um Direcionamento sem formulário nenhum não tem
            // propósito.
            'formularios' => ['required', 'array', 'min:1'],
            'formularios.*.tipo_registro_uuid' => [
                'required', 'string', 'distinct',
                Rule::exists('tipos_registro', 'uuid')->where('empresa_id', $empresaId),
            ],
            'formularios.*.obrigatorio' => ['nullable', 'boolean'],
            'formularios.*.calcula_percentual_compliance' => ['nullable', 'boolean'],
        ];
    }
}

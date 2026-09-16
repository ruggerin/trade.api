<?php

namespace App\Http\Requests\Direcionamento;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateDirecionamentoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $empresaId = $this->route('direcionamento')?->empresa_id;

        return [
            'descricao' => ['sometimes', 'required', 'string', 'max:255'],
            'vigencia_inicio' => ['sometimes', 'required', 'date'],
            'vigencia_fim' => ['sometimes', 'required', 'date', 'after_or_equal:vigencia_inicio'],
            // false dispara o cancelamento em cascata das OS PENDENTE geradas — ver
            // DirecionamentoController::update.
            'ativo' => ['sometimes', 'boolean'],

            'ponto_venda_uuids' => ['sometimes', 'nullable', 'array'],
            'ponto_venda_uuids.*' => [
                'string', Rule::exists('pontos_venda', 'uuid')->where('empresa_id', $empresaId),
            ],
            'rede_loja_uuids' => ['sometimes', 'nullable', 'array'],
            'rede_loja_uuids.*' => [
                'string', Rule::exists('redes_lojas', 'uuid')->where('empresa_id', $empresaId),
            ],
            'promotor_uuids' => ['sometimes', 'nullable', 'array'],
            'promotor_uuids.*' => [
                'string',
                Rule::exists('usuarios', 'uuid')->where('empresa_id', $empresaId)->where('user_type', 'PROMOTOR'),
            ],

            'formularios' => ['sometimes', 'array', 'min:1'],
            'formularios.*.tipo_registro_uuid' => [
                'required', 'string', 'distinct',
                Rule::exists('tipos_registro', 'uuid')->where('empresa_id', $empresaId),
            ],
            'formularios.*.obrigatorio' => ['nullable', 'boolean'],
            'formularios.*.calcula_percentual_compliance' => ['nullable', 'boolean'],
        ];
    }
}

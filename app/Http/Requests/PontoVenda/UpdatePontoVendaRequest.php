<?php

namespace App\Http\Requests\PontoVenda;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePontoVendaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'codigo_externo' => ['sometimes', 'nullable', 'string', 'max:50'],
            'cnpj' => [
                'sometimes', 'nullable', 'string', 'max:20',
                Rule::unique('pontos_venda', 'cnpj')
                    ->where('empresa_id', $this->route('pontoVenda')?->empresa_id)
                    ->ignore($this->route('pontoVenda')),
            ],
            'razao_social' => ['sometimes', 'required', 'string', 'max:255'],
            'fantasia' => ['sometimes', 'required', 'string', 'max:255'],
            'latitude' => ['sometimes', 'required', 'numeric', 'between:-90,90'],
            'longitude' => ['sometimes', 'required', 'numeric', 'between:-180,180'],
            'endereco' => ['sometimes', 'required', 'string', 'max:255'],
            'numero' => ['sometimes', 'nullable', 'string', 'max:10'],
            'bairro' => ['sometimes', 'nullable', 'string', 'max:100'],
            'cidade' => ['sometimes', 'required', 'string', 'max:100'],
            'cep' => ['sometimes', 'nullable', 'string', 'max:10'],
            'telefone' => ['sometimes', 'nullable', 'string', 'max:50'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'ativo' => ['sometimes', 'boolean'],
        ];
    }
}

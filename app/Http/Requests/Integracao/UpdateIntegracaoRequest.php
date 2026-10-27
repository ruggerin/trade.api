<?php

namespace App\Http\Requests\Integracao;

use Illuminate\Foundation\Http\FormRequest;

class UpdateIntegracaoRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'nome' => ['sometimes', 'string', 'max:100'],
            'descricao' => ['nullable', 'string', 'max:2000'],
            ...StoreIntegracaoRequest::regrasAcesso(parcial: true),
        ];
    }

    public function messages(): array
    {
        return (new StoreIntegracaoRequest)->messages();
    }
}

<?php

namespace App\Http\Requests\Parametro;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateParametroRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'chave' => [
                'sometimes', 'required', 'string', 'max:100', 'regex:/^[A-Z0-9_]+$/',
                Rule::unique('parametros', 'chave')
                    ->where('empresa_id', $this->user()->empresa_id)
                    ->ignore($this->route('parametro')),
            ],
            'valor' => ['sometimes', 'required', 'string'],
            'descricao' => ['sometimes', 'nullable', 'string', 'max:255'],
            'ativo' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'chave.regex' => 'A chave deve conter apenas letras maiúsculas, números e underscore (ex.: CHECKIN_RAIO_METROS).',
        ];
    }
}

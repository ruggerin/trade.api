<?php

namespace App\Http\Requests\Parametro;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreParametroRequest extends FormRequest
{
    public function authorize(): bool
    {
        // user_type/permissão já validados pelo middleware da rota.
        return true;
    }

    public function rules(): array
    {
        return [
            'chave' => [
                'required', 'string', 'max:100', 'regex:/^[A-Z0-9_]+$/',
                Rule::unique('parametros', 'chave')->where('empresa_id', $this->user()->empresa_id),
            ],
            'valor' => ['required', 'string'],
            'descricao' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'chave.regex' => 'A chave deve conter apenas letras maiúsculas, números e underscore (ex.: CHECKIN_RAIO_METROS).',
        ];
    }
}

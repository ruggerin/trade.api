<?php

namespace App\Http\Requests\TipoVisita;

use Illuminate\Foundation\Http\FormRequest;

class UpdateTipoVisitaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'descricao' => ['sometimes', 'required', 'string', 'max:60'],
            'cor' => ['sometimes', 'required', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'ativo' => ['sometimes', 'boolean'],
        ];
    }
}

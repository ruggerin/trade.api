<?php

namespace App\Http\Requests\ObjetivoVisita;

use Illuminate\Foundation\Http\FormRequest;

class UpdateObjetivoVisitaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'descricao' => ['sometimes', 'required', 'string', 'max:60'],
            'ativo' => ['sometimes', 'boolean'],
        ];
    }
}

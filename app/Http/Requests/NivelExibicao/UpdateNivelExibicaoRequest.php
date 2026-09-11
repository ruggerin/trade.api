<?php

namespace App\Http\Requests\NivelExibicao;

use Illuminate\Foundation\Http\FormRequest;

class UpdateNivelExibicaoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'descricao' => ['sometimes', 'required', 'string', 'max:255'],
            'ativo' => ['sometimes', 'boolean'],
        ];
    }
}

<?php

namespace App\Http\Requests\RedeLoja;

use Illuminate\Foundation\Http\FormRequest;

class UpdateRedeLojaRequest extends FormRequest
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

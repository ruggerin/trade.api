<?php

namespace App\Http\Requests\Perfil;

use App\Enums\Permissao;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePerfilRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'nome' => ['sometimes', 'required', 'string', 'max:255'],
            'descricao' => ['sometimes', 'nullable', 'string', 'max:255'],
            'permissoes' => ['sometimes', 'array'],
            'permissoes.*' => [Rule::in(array_column(Permissao::cases(), 'value'))],
            'ativo' => ['sometimes', 'boolean'],
        ];
    }
}

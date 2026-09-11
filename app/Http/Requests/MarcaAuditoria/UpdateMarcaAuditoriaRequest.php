<?php

namespace App\Http\Requests\MarcaAuditoria;

use App\Enums\Propriedade;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateMarcaAuditoriaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'descricao' => ['sometimes', 'required', 'string', 'max:255'],
            'propriedade' => ['sometimes', 'required', Rule::enum(Propriedade::class)],
            'ativo' => ['sometimes', 'boolean'],
        ];
    }
}

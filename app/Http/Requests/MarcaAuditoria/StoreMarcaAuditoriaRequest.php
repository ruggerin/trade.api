<?php

namespace App\Http\Requests\MarcaAuditoria;

use App\Enums\Propriedade;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMarcaAuditoriaRequest extends FormRequest
{
    public function authorize(): bool
    {
        // user_type/permissão já validados pelo middleware da rota.
        return true;
    }

    public function rules(): array
    {
        return [
            'descricao' => ['required', 'string', 'max:255'],
            'propriedade' => ['required', Rule::enum(Propriedade::class)],
        ];
    }
}

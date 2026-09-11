<?php

namespace App\Http\Requests\TipoVisita;

use Illuminate\Foundation\Http\FormRequest;

class StoreTipoVisitaRequest extends FormRequest
{
    public function authorize(): bool
    {
        // user_type/permissão já validados pelo middleware 'permissao:ordens_servico.gerenciar'.
        return true;
    }

    public function rules(): array
    {
        return [
            'descricao' => ['required', 'string', 'max:60'],
            'cor' => ['required', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
        ];
    }
}

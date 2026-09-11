<?php

namespace App\Http\Requests\ObjetivoVisita;

use Illuminate\Foundation\Http\FormRequest;

class StoreObjetivoVisitaRequest extends FormRequest
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
        ];
    }
}

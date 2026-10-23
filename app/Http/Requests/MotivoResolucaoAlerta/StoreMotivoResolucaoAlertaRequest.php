<?php

namespace App\Http\Requests\MotivoResolucaoAlerta;

use Illuminate\Foundation\Http\FormRequest;

class StoreMotivoResolucaoAlertaRequest extends FormRequest
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
            // Resolver com este motivo conta como informação que virou ação (régua de XP, docs/88 §9.2).
            'procedente' => ['nullable', 'boolean'],
        ];
    }
}

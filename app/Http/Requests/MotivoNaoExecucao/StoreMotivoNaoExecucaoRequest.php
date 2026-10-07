<?php

namespace App\Http\Requests\MotivoNaoExecucao;

use Illuminate\Foundation\Http\FormRequest;

class StoreMotivoNaoExecucaoRequest extends FormRequest
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
        ];
    }
}

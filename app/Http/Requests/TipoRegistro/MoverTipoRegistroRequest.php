<?php

namespace App\Http\Requests\TipoRegistro;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MoverTipoRegistroRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'direcao' => ['required', Rule::in(['cima', 'baixo'])],
        ];
    }
}

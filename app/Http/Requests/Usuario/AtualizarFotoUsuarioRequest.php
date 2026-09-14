<?php

namespace App\Http\Requests\Usuario;

use Illuminate\Foundation\Http\FormRequest;

class AtualizarFotoUsuarioRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        // Mesmo limite de StoreVisitaRegistroRequest (8MB) — sem validar dimensão/proporção,
        // é só uma foto de perfil, não uma prova de campo.
        return [
            'imagem' => ['required', 'file', 'image', 'max:8192'],
        ];
    }
}

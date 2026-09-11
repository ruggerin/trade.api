<?php

namespace App\Http\Requests\Perfil;

use App\Enums\Permissao;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePerfilRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Gestão de perfil é sempre user_type:ADMIN — nunca delegável por permissão (evita um
        // GESTOR se auto-promover editando o próprio perfil). Já validado na rota.
        return true;
    }

    public function rules(): array
    {
        return [
            'nome' => ['required', 'string', 'max:255'],
            'descricao' => ['nullable', 'string', 'max:255'],
            'permissoes' => ['sometimes', 'array'],
            'permissoes.*' => [Rule::in(array_column(Permissao::cases(), 'value'))],
        ];
    }
}

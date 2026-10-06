<?php

namespace App\Http\Requests\Auth;

use App\Enums\TipoDocumentoLegal;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SignupEmpresaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'razao_social' => ['required', 'string', 'max:255'],
            'nome_fantasia' => ['required', 'string', 'max:255'],
            'cnpj' => ['required', 'string', 'max:20', 'unique:empresas,cnpj'],
            'admin_nome' => ['required', 'string', 'max:255'],
            'admin_email' => ['required', 'email', 'unique:usuarios,email'],
            'admin_senha' => ['required', 'string', 'min:8'],
            // Aceite dos Termos/Política no próprio cadastro (docs/58 §7) — opcional enquanto não
            // houver tela de signup; quando vier, grava junto e o ADMIN não vê a tela de aceite.
            'documentos' => ['sometimes', 'array'],
            'documentos.*.tipo' => ['required', 'string', Rule::enum(TipoDocumentoLegal::class)],
            'documentos.*.versao' => ['required', 'string', 'max:20'],
        ];
    }
}

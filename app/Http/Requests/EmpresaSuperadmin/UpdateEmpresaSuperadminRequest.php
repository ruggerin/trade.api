<?php

namespace App\Http\Requests\EmpresaSuperadmin;

use App\Enums\PlanoEmpresa;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateEmpresaSuperadminRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'razao_social' => ['sometimes', 'required', 'string', 'max:255'],
            'nome_fantasia' => ['sometimes', 'required', 'string', 'max:255'],
            'cnpj' => ['sometimes', 'required', 'string', 'max:20', Rule::unique('empresas', 'cnpj')->ignore($this->route('empresa'))],
            'plano' => ['sometimes', 'required', Rule::enum(PlanoEmpresa::class)],
            'limite_usuarios' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'limite_pontos_venda' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'limite_licencas' => ['sometimes', 'nullable', 'integer', 'min:1'],
            // Reativar uma empresa bloqueada passa por aqui (ativo: true) — o bloqueio em si é
            // via DELETE (mesmo padrão soft-delete do resto da API), ver
            // EmpresaController::destroySuperadmin.
            'ativo' => ['sometimes', 'boolean'],
        ];
    }
}

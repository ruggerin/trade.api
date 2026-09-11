<?php

namespace App\Http\Requests\Contrato;

use App\Enums\TipoContrato;
use App\Enums\UserType;
use App\Models\Empresa;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreContratoRequest extends FormRequest
{
    public function authorize(): bool
    {
        // user_type/permissão já validados pelo middleware 'permissao:contratos.gerenciar'.
        return true;
    }

    public function rules(): array
    {
        $isSuperadmin = $this->user()->user_type === UserType::SUPERADMIN;

        // Empresa alvo pra resolver o ponto_venda_uuid contra a empresa certa: a própria
        // (ADMIN/GESTOR) ou a escolhida no formulário (SUPERADMIN, ver empresa_uuid abaixo) —
        // mesmo motivo de StoreUsuarioRequest::empresaIdAlvo (SUPERADMIN não tem empresa_id).
        $empresaIdAlvo = $isSuperadmin
            ? Empresa::where('uuid', $this->input('empresa_uuid'))->value('id')
            : $this->user()->empresa_id;

        return [
            // Só SUPERADMIN manda isso (escolhe em qual empresa criar) — obrigatório pra ele,
            // proibido pros outros (ver withValidator), mesmo padrão de StoreUsuarioRequest.
            'empresa_uuid' => [
                Rule::requiredIf($isSuperadmin), 'nullable', 'string',
                Rule::exists('empresas', 'uuid'),
            ],
            'ponto_venda_uuid' => [
                'required', 'string',
                Rule::exists('pontos_venda', 'uuid')->where('empresa_id', $empresaIdAlvo),
            ],
            'tipo' => ['required', Rule::in(array_column(TipoContrato::cases(), 'value'))],
            'descricao' => ['nullable', 'string'],
            'vigencia_inicio' => ['required', 'date'],
            'vigencia_fim' => ['required', 'date', 'after_or_equal:vigencia_inicio'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->filled('empresa_uuid') && $this->user()->user_type !== UserType::SUPERADMIN) {
                $validator->errors()->add('empresa_uuid', 'Só o suporte pode escolher a empresa ao criar um contrato.');
            }
        });
    }
}

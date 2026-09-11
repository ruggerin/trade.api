<?php

namespace App\Http\Requests\Contrato;

use App\Enums\TipoContrato;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateContratoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Validado contra a empresa do contrato alvo (não do chamador) — faz diferença pra
            // SUPERADMIN, que não tem empresa própria, mesmo motivo de
            // UpdateUsuarioRequest::perfil_uuid.
            'ponto_venda_uuid' => [
                'sometimes', 'required', 'string',
                Rule::exists('pontos_venda', 'uuid')->where('empresa_id', $this->route('contrato')?->empresa_id),
            ],
            'tipo' => ['sometimes', 'required', Rule::in(array_column(TipoContrato::cases(), 'value'))],
            'descricao' => ['sometimes', 'nullable', 'string'],
            'vigencia_inicio' => ['sometimes', 'required', 'date'],
            'vigencia_fim' => ['sometimes', 'required', 'date', 'after_or_equal:vigencia_inicio'],
            'ativo' => ['sometimes', 'boolean'],
        ];
    }
}

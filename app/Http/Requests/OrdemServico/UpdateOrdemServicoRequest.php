<?php

namespace App\Http\Requests\OrdemServico;

use App\Enums\StatusOrdemServico;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateOrdemServicoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        // Validado contra a empresa da própria OS (route model binding já aplica o global scope
        // de tenant) — mesmo raciocínio de UpdateContratoRequest.
        $empresaId = $this->route('ordemServico')?->empresa_id;

        return [
            'ponto_venda_uuid' => [
                'sometimes', 'required', 'string',
                Rule::exists('pontos_venda', 'uuid')->where('empresa_id', $empresaId),
            ],
            'usuario_uuid' => [
                'sometimes', 'nullable', 'string',
                Rule::exists('usuarios', 'uuid')->where('empresa_id', $empresaId)->where('user_type', 'PROMOTOR'),
            ],
            'tipo_visita_uuid' => [
                'sometimes', 'nullable', 'string',
                Rule::exists('tipos_visita', 'uuid')->where('empresa_id', $empresaId),
            ],
            'objetivo_visita_uuid' => [
                'sometimes', 'nullable', 'string',
                Rule::exists('objetivos_visita', 'uuid')->where('empresa_id', $empresaId),
            ],
            'prioridade' => ['sometimes', 'nullable', Rule::in(['BAIXA', 'MEDIA', 'ALTA'])],
            'horario_previsto' => ['sometimes', 'nullable', 'date_format:H:i'],
            'obrigatoria' => ['sometimes', 'boolean'],
            'prazo_inicio' => ['sometimes', 'required', 'date'],
            'prazo_fim' => ['sometimes', 'required', 'date', 'after_or_equal:prazo_inicio'],
            'observacao' => ['sometimes', 'nullable', 'string'],
            // Única transição manual permitida por aqui — CONCLUIDA/EM_ANDAMENTO são geridas
            // pelo próprio fluxo de check-in/checkout (ver VisitaController), nunca por edição
            // direta.
            'status' => ['sometimes', Rule::in([StatusOrdemServico::CANCELADA->value])],
            // Vínculo direto de formulário numa OS avulsa — ver docs/25 §7.2.
            'formularios' => ['sometimes', 'nullable', 'array'],
            'formularios.*.tipo_registro_uuid' => [
                'required', 'string', 'distinct',
                Rule::exists('tipos_registro', 'uuid')->where('empresa_id', $empresaId),
            ],
            'formularios.*.obrigatorio' => ['nullable', 'boolean'],
            'formularios.*.calcula_percentual_compliance' => ['nullable', 'boolean'],
        ];
    }
}

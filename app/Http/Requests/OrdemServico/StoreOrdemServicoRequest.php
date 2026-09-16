<?php

namespace App\Http\Requests\OrdemServico;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreOrdemServicoRequest extends FormRequest
{
    public function authorize(): bool
    {
        // user_type/permissão já validados pelo middleware 'permissao:ordens_servico.gerenciar'.
        return true;
    }

    public function rules(): array
    {
        $empresaId = $this->user()->empresa_id;

        return [
            'ponto_venda_uuid' => [
                'required', 'string',
                Rule::exists('pontos_venda', 'uuid')->where('empresa_id', $empresaId),
            ],
            // null/ausente = fila aberta, qualquer promotor da empresa pode atender.
            'usuario_uuid' => [
                'nullable', 'string',
                Rule::exists('usuarios', 'uuid')->where('empresa_id', $empresaId)->where('user_type', 'PROMOTOR'),
            ],
            'tipo_visita_uuid' => [
                'nullable', 'string',
                Rule::exists('tipos_visita', 'uuid')->where('empresa_id', $empresaId),
            ],
            'objetivo_visita_uuid' => [
                'nullable', 'string',
                Rule::exists('objetivos_visita', 'uuid')->where('empresa_id', $empresaId),
            ],
            'prioridade' => ['nullable', Rule::in(['BAIXA', 'MEDIA', 'ALTA'])],
            'horario_previsto' => ['nullable', 'date_format:H:i'],
            'obrigatoria' => ['nullable', 'boolean'],
            'prazo_inicio' => ['required', 'date'],
            'prazo_fim' => ['required', 'date', 'after_or_equal:prazo_inicio'],
            'observacao' => ['nullable', 'string'],
            // Vínculo direto de formulário numa OS avulsa, sem Direcionamento nenhum por trás —
            // ver docs/25-DIRECIONAMENTO-ORDEM-SERVICO.md §7.2.
            'formularios' => ['nullable', 'array'],
            'formularios.*.tipo_registro_uuid' => [
                'required', 'string', 'distinct',
                Rule::exists('tipos_registro', 'uuid')->where('empresa_id', $empresaId),
            ],
            'formularios.*.obrigatorio' => ['nullable', 'boolean'],
            'formularios.*.calcula_percentual_compliance' => ['nullable', 'boolean'],
        ];
    }
}

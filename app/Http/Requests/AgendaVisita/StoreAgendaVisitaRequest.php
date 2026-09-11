<?php

namespace App\Http\Requests\AgendaVisita;

use App\Models\PontoVenda;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreAgendaVisitaRequest extends FormRequest
{
    public function authorize(): bool
    {
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
            // Diferente de OrdemServico manual, aqui não existe fila aberta — a agenda é sempre
            // de um promotor específico. Ver docs/10-AGENDA-VISITA.md, decisão 1.
            'usuario_uuid' => [
                'required', 'string',
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
            'recorrencia' => ['required', Rule::in(['SEMANAL', 'DATA_UNICA'])],
            'dia_semana' => ['required_if:recorrencia,SEMANAL', 'nullable', 'integer', 'between:0,6'],
            'data' => ['required_if:recorrencia,DATA_UNICA', 'nullable', 'date'],
            'horario_previsto' => ['nullable', 'date_format:H:i'],
            'obrigatoria' => ['nullable', 'boolean'],
            'observacao' => ['nullable', 'string'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (! $this->filled('ponto_venda_uuid') || ! $this->filled('usuario_uuid')) {
                return;
            }

            $pontoVenda = PontoVenda::withoutGlobalScopes()->where('uuid', $this->input('ponto_venda_uuid'))->first();
            if (! $pontoVenda) {
                return; // já rejeitado pelo Rule::exists acima
            }

            $vinculado = $pontoVenda->promotores()->where('usuarios.uuid', $this->input('usuario_uuid'))->exists();
            if (! $vinculado) {
                $validator->errors()->add('usuario_uuid', 'Este promotor não está vinculado a este ponto de venda.');
            }
        });
    }
}

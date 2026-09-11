<?php

namespace App\Http\Requests\AgendaVisita;

use App\Models\PontoVenda;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateAgendaVisitaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $empresaId = $this->route('agendaVisita')?->empresa_id;

        return [
            'ponto_venda_uuid' => [
                'sometimes', 'required', 'string',
                Rule::exists('pontos_venda', 'uuid')->where('empresa_id', $empresaId),
            ],
            'usuario_uuid' => [
                'sometimes', 'required', 'string',
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
            'prioridade' => ['sometimes', Rule::in(['BAIXA', 'MEDIA', 'ALTA'])],
            'recorrencia' => ['sometimes', Rule::in(['SEMANAL', 'DATA_UNICA'])],
            'dia_semana' => ['sometimes', 'nullable', 'integer', 'between:0,6'],
            'data' => ['sometimes', 'nullable', 'date'],
            'horario_previsto' => ['sometimes', 'nullable', 'date_format:H:i'],
            'obrigatoria' => ['sometimes', 'boolean'],
            'ativo' => ['sometimes', 'boolean'],
            'observacao' => ['sometimes', 'nullable', 'string'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $agendaVisita = $this->route('agendaVisita');

            $pontoVendaUuid = $this->input('ponto_venda_uuid');
            $usuarioUuid = $this->input('usuario_uuid');

            // Só recheca o vínculo quando pelo menos um dos dois lados está mudando — o valor
            // não-alterado é lido da própria agenda (já sabidamente válido).
            if (! $this->has('ponto_venda_uuid') && ! $this->has('usuario_uuid')) {
                return;
            }

            $pontoVenda = $pontoVendaUuid
                ? PontoVenda::withoutGlobalScopes()->where('uuid', $pontoVendaUuid)->first()
                : $agendaVisita?->pontoVenda;

            if (! $pontoVenda) {
                return; // já rejeitado pelo Rule::exists acima
            }

            $usuarioUuid ??= $agendaVisita?->usuario?->uuid;
            if (! $usuarioUuid) {
                return;
            }

            $vinculado = $pontoVenda->promotores()->where('usuarios.uuid', $usuarioUuid)->exists();
            if (! $vinculado) {
                $validator->errors()->add('usuario_uuid', 'Este promotor não está vinculado a este ponto de venda.');
            }
        });
    }
}

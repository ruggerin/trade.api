<?php

namespace App\Http\Requests\OrdemServico;

use App\Models\PontoVenda;
use App\Support\VisibilidadePontosVenda;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * "+ Compromisso" self-agendado — qualquer autenticado pode criar uma OS pra si mesmo (não
 * exige `ordens_servico.gerenciar`, ownership em vez de RBAC, mesmo espírito de
 * StoreVisitaRequest). Ver docs/13-AGENDA-MOBILE-E-AUTONOMIA.md.
 */
class StoreOrdemServicoPropriaRequest extends FormRequest
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
            'prazo_inicio' => ['required', 'date'],
            'prazo_fim' => ['required', 'date', 'after_or_equal:prazo_inicio'],
            'observacao' => ['nullable', 'string'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (! $this->filled('ponto_venda_uuid')) {
                return;
            }

            $pontoVenda = PontoVenda::withoutGlobalScopes()->where('uuid', $this->input('ponto_venda_uuid'))->first();
            if (! $pontoVenda) {
                return; // já rejeitado pelo Rule::exists acima
            }

            if (! VisibilidadePontosVenda::visivelParaPromotor($pontoVenda->id, $this->user())) {
                $validator->errors()->add('ponto_venda_uuid', 'Você não tem acesso a este ponto de venda.');
            }
        });
    }
}

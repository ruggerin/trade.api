<?php

namespace App\Http\Requests\Visita;

use App\Enums\StatusOrdemServico;
use App\Models\OrdemServico;
use App\Models\PontoVenda;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class CheckinVisitaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'ponto_venda_uuid' => ['required', 'string'],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            // Opcional — quando o check-in nasce de uma OrdemServico direcionada (ver
            // docs/07-ORDEM-DE-SERVICO.md), em vez de uma visita espontânea.
            'ordem_servico_uuid' => [
                'nullable', 'string',
                Rule::exists('ordens_servico', 'uuid')->where('empresa_id', $this->user()->empresa_id),
            ],
            // Idempotência de check-in — ver VisitaController::store. Só formato aqui, de
            // propósito SEM `unique:visitas`: o reenvio da mesma chave é o caso esperado (fila
            // de envio retransmitindo depois de perder a resposta), tratado no controller
            // devolvendo a visita já criada em vez de rejeitar.
            'idempotency_key' => ['nullable', 'string', 'uuid'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (! $this->filled('ordem_servico_uuid')) {
                return;
            }

            $ordemServico = OrdemServico::withoutGlobalScopes()
                ->where('uuid', $this->input('ordem_servico_uuid'))
                ->first();

            if (! $ordemServico) {
                return; // já rejeitado pelo Rule::exists acima
            }

            if ($ordemServico->status !== StatusOrdemServico::PENDENTE) {
                $validator->errors()->add('ordem_servico_uuid', 'Esta ordem de serviço não está mais pendente.');

                return;
            }

            if ($ordemServico->usuario_id !== null && $ordemServico->usuario_id !== $this->user()->id) {
                $validator->errors()->add('ordem_servico_uuid', 'Esta ordem de serviço foi destinada a outro promotor.');

                return;
            }

            $pontoVenda = PontoVenda::withoutGlobalScopes()
                ->where('uuid', $this->input('ponto_venda_uuid'))
                ->first();

            if ($pontoVenda && $ordemServico->ponto_venda_id !== $pontoVenda->id) {
                $validator->errors()->add('ordem_servico_uuid', 'Esta ordem de serviço não é para este ponto de venda.');
            }
        });
    }
}

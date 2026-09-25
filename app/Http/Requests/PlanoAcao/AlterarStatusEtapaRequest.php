<?php

namespace App\Http\Requests\PlanoAcao;

use App\Enums\StatusEtapaPlanoAcao;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AlterarStatusEtapaRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Permissão já validada pelo middleware 'permissao:planos_acao.movimentar_etapa'.
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['required', Rule::enum(StatusEtapaPlanoAcao::class)],
            // Cancelar ("pular") ou bloquear sempre exige dizer por quê — é o que o histórico
            // mostra depois pra quem for auditar onde o plano travou (docs/37 §4.9).
            'motivo' => [
                Rule::requiredIf(fn () => in_array($this->input('status'), [
                    StatusEtapaPlanoAcao::CANCELADA->value, StatusEtapaPlanoAcao::BLOQUEADA->value,
                ], true)),
                'nullable', 'string', 'max:2000',
            ],
            // Evidência estruturada (nº do pedido, nº da NF) e/ou anexo — só aceita junto de FEITA.
            'evidencia_texto' => ['nullable', 'string', 'max:2000'],
            'evidencia_arquivo' => ['nullable', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png,webp'],
        ];
    }
}

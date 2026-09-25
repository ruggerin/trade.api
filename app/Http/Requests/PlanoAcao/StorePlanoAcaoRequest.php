<?php

namespace App\Http\Requests\PlanoAcao;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePlanoAcaoRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Permissão já validada pelo middleware 'permissao:planos_acao.criar'.
        return true;
    }

    public function rules(): array
    {
        $empresaId = $this->user()->empresa_id;

        return [
            // Com registro_uuid = plano de alerta (loja vem do alerta); sem = plano livre (§4.2),
            // opcionalmente ligado a UMA loja OU UMA rede. Existência/tenant/eh_alerta do
            // registro são checados no controller (VisitaRegistro não tem empresa_id próprio).
            'registro_uuid' => ['nullable', 'uuid', 'prohibits:ponto_venda_uuid,rede_loja_uuid'],
            'ponto_venda_uuid' => [
                'nullable', 'string', 'prohibits:rede_loja_uuid',
                Rule::exists('pontos_venda', 'uuid')->where('empresa_id', $empresaId),
            ],
            'rede_loja_uuid' => [
                'nullable', 'string',
                Rule::exists('redes_lojas', 'uuid')->where('empresa_id', $empresaId),
            ],
            'titulo' => ['required', 'string', 'max:255'],
            'descricao' => ['nullable', 'string', 'max:5000'],
            'prazo' => ['nullable', 'date_format:Y-m-d'],
            // Etapas montadas na mão — moldes reaproveitáveis (§4.3) são a fase 2.
            'etapas' => ['required', 'array', 'min:1', 'max:30'],
            ...EtapaRules::para($empresaId, 'etapas.*.'),
        ];
    }

    public function messages(): array
    {
        return [
            'registro_uuid.prohibits' => 'Plano de alerta já herda a loja do alerta — não informe loja/rede.',
            'ponto_venda_uuid.prohibits' => 'Escolha uma loja ou uma rede, não as duas.',
        ];
    }
}

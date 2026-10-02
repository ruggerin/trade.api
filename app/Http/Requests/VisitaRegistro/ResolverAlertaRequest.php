<?php

namespace App\Http\Requests\VisitaRegistro;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Motivo do fechamento rápido de alerta (docs/56) — pelo menos um dos dois precisa vir: o
 * catálogo (`motivo_uuid`) e/ou texto livre (`motivo_texto`, complementa ou substitui o
 * catálogo). user_type (ADMIN/GESTOR) continua checado no controller, não aqui — mesmo padrão
 * de StorePedidoRequest.
 */
class ResolverAlertaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'motivo_uuid' => [
                'nullable', 'required_without:motivo_texto', 'string',
                Rule::exists('motivos_resolucao_alerta', 'uuid')->where('empresa_id', $this->user()->empresa_id),
            ],
            'motivo_texto' => ['nullable', 'required_without:motivo_uuid', 'string', 'max:2000'],
        ];
    }
}

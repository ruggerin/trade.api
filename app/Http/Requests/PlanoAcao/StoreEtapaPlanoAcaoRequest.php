<?php

namespace App\Http\Requests\PlanoAcao;

use Illuminate\Foundation\Http\FormRequest;

class StoreEtapaPlanoAcaoRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Permissão já validada pelo middleware 'permissao:planos_acao.movimentar_etapa'.
        return true;
    }

    public function rules(): array
    {
        return EtapaRules::para($this->user()->empresa_id);
    }
}

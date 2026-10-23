<?php

namespace App\Http\Requests\Visita;

use Illuminate\Foundation\Http\FormRequest;

class CheckoutVisitaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            // Hora em que o promotor finalizou no campo (docs/51 Fase 2) — opcional, ver
            // App\Support\HorarioDoCampo.
            'fim_em' => ['nullable', 'date'],
            // Obrigatórias feitas/total que o app mostrou no "Finalizar visita" (régua de XP,
            // docs/88 §5.1). Opcionais: app antigo não manda.
            'obrigatorias_total' => ['nullable', 'integer', 'min:0', 'max:500'],
            'obrigatorias_feitas' => ['nullable', 'integer', 'min:0', 'lte:obrigatorias_total'],
            // Promotor aceitou finalizar no horário em que saiu da loja (docs/92 peça B). Só o
            // pedido: a hora e a posição o servidor recalcula — o app nunca manda a hora da saída.
            'usar_saida_detectada' => ['nullable', 'boolean'],
        ];
    }
}

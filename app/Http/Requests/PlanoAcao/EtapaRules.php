<?php

namespace App\Http\Requests\PlanoAcao;

use Illuminate\Validation\Rule;

/**
 * Regras de uma etapa, compartilhadas entre criar o plano (etapas.*) e adicionar uma etapa
 * avulsa depois ("+ Adicionar nova etapa" do protótipo, docs/37-agentes/37-PROTOTIPO.md).
 */
final class EtapaRules
{
    /** @return array<string, array<int, mixed>> */
    public static function para(int $empresaId, string $prefixo = ''): array
    {
        return [
            "{$prefixo}titulo" => ['required', 'string', 'max:255'],
            "{$prefixo}descricao" => ['nullable', 'string', 'max:5000'],
            "{$prefixo}prazo" => ['nullable', 'date_format:Y-m-d'],
            // Qualquer usuário ativo da empresa — inclusive PROMOTOR (ex.: conferir a gôndola).
            "{$prefixo}responsavel_uuid" => [
                'nullable', 'string',
                Rule::exists('usuarios', 'uuid')->where('empresa_id', $empresaId)->where('ativo', true),
            ],
            // Ator externo ao sistema (vendedor, motorista) — sempre movimentado por alguém do
            // sistema com planos_acao.movimentar_etapa, ver docs/37 §6.
            "{$prefixo}responsavel_externo_nome" => ['nullable', 'string', 'max:255'],
            "{$prefixo}responsavel_externo_contato" => ['nullable', 'string', 'max:255'],
            "{$prefixo}evidencia_obrigatoria" => ['nullable', 'boolean'],
        ];
    }
}

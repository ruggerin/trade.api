<?php

namespace App\Http\Requests\Integracao;

use App\Enums\EscopoIntegracao;
use App\Support\TokenIntegracao;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** docs/96 §8 — rota só de ADMIN (user_type:ADMIN), então authorize() fica no middleware. */
class StoreIntegracaoRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'nome' => ['required', 'string', 'max:100'],
            'descricao' => ['nullable', 'string', 'max:2000'],
            ...self::regrasAcesso(),
            // null = sem validade (docs/96 §11 #2); a tela sugere 365.
            'validade_dias' => ['nullable', 'integer', Rule::in(TokenIntegracao::VALIDADES)],
        ];
    }

    /** Regras compartilhadas com a edição: permissões, IPs e limite. */
    public static function regrasAcesso(bool $parcial = false): array
    {
        $obrigatorio = $parcial ? 'sometimes' : 'required';

        return [
            // Pelo menos um: nenhum token nasce sem permissão (nem com todas, sem o ADMIN marcar).
            'escopos' => [$obrigatorio, 'array', 'min:1'],
            'escopos.*' => ['string', 'distinct', Rule::in(EscopoIntegracao::valoresDisponiveis())],
            'ips_permitidos' => ['nullable', 'array', 'max:50'],
            'ips_permitidos.*' => ['string', 'max:49', function (string $atributo, mixed $valor, Closure $falhar): void {
                if (! self::ipOuFaixaValido((string) $valor)) {
                    $falhar("\"{$valor}\" não é um IP nem uma faixa (ex.: 200.150.12.4 ou 52.67.0.0/16).");
                }
            }],
            'limite_por_minuto' => ['sometimes', 'integer', 'min:1', 'max:1000'],
        ];
    }

    public static function ipOuFaixaValido(string $valor): bool
    {
        [$ip, $mascara] = array_pad(explode('/', $valor, 2), 2, null);
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return false;
        }
        if ($mascara === null) {
            return true;
        }
        $max = str_contains($ip, ':') ? 128 : 32;

        return ctype_digit($mascara) && (int) $mascara >= 0 && (int) $mascara <= $max;
    }

    public function messages(): array
    {
        return [
            'escopos.required' => 'Marque pelo menos uma permissão de acesso.',
            'escopos.min' => 'Marque pelo menos uma permissão de acesso.',
            'escopos.*.in' => 'Permissão de acesso inválida ou ainda não disponível.',
        ];
    }
}

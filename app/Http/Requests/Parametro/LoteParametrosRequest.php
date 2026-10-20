<?php

namespace App\Http\Requests\Parametro;

use App\Support\ConfiguracoesEmpresa;
use App\Support\ParametrosMetadados;
use App\Support\ParametrosPadrao;
use App\Support\Xp\ComposicaoXp;
use App\Support\Xp\ParametrosXp;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Gravação em lote da tela de Configurações (docs/62). Quem manda no valor é o servidor: a tela
 * valida por conforto, mas fora da faixa, fora das opções ou chave que não é do catálogo é
 * recusado — e o lote inteiro, não só o item (tudo ou nada).
 */
class LoteParametrosRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'alteracoes' => ['required', 'array', 'min:1', 'max:60'],
            'alteracoes.*.chave' => ['required', 'string', 'distinct'],
            'alteracoes.*.valor' => ['nullable'],
            'alteracoes.*.ativo' => ['nullable', 'boolean'],
        ];
    }

    /** Os pesos da composição do XP (docs/88 §5.1) somam 100, contando os que não mudaram. */
    private function validarSomaDosPesos(Validator $validator): void
    {
        $empresa = $this->user()->empresa;
        $porChave = array_flip(ParametrosXp::CHAVES_PESO);
        $pesos = $empresa ? ParametrosXp::pesosConfigurados($empresa) : ComposicaoXp::PARTES;
        $mexeu = false;
        foreach ((array) $this->input('alteracoes', []) as $alteracao) {
            $chave = $alteracao['chave'] ?? null;
            if (is_string($chave) && isset($porChave[$chave]) && isset($alteracao['valor']) && is_numeric($alteracao['valor'])) {
                $pesos[$porChave[$chave]] = (int) $alteracao['valor'];
                $mexeu = true;
            }
        }
        if ($mexeu && array_sum($pesos) !== 100) {
            $validator->errors()->add('alteracoes', 'Os pesos do XP da visita precisam somar 100% (hoje somam '.array_sum($pesos).'%).');
        }
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach ((array) $this->input('alteracoes', []) as $i => $alteracao) {
                $chave = $alteracao['chave'] ?? null;
                if (! is_string($chave)) {
                    continue;
                }

                if (! array_key_exists($chave, ParametrosPadrao::CATALOGO)) {
                    $validator->errors()->add("alteracoes.$i.chave", 'Esta configuração não existe no catálogo.');

                    continue;
                }

                $meta = ParametrosMetadados::de($chave);

                if ($bloqueio = ConfiguracoesEmpresa::bloqueioDeContrato($this->user()->empresa, $chave)) {
                    $validator->errors()->add("alteracoes.$i.chave", "{$meta['rotulo']}: $bloqueio");

                    continue;
                }

                $temValor = array_key_exists('valor', $alteracao) && $alteracao['valor'] !== null;
                $temAtivo = array_key_exists('ativo', $alteracao) && $alteracao['ativo'] !== null;

                if (! $temValor && ! $temAtivo) {
                    $validator->errors()->add("alteracoes.$i.valor", 'Informe o valor.');
                }

                if ($temValor && ($erro = ConfiguracoesEmpresa::erroDoValor($chave, $alteracao['valor']))) {
                    $validator->errors()->add("alteracoes.$i.valor", "{$meta['rotulo']}: $erro");
                }

                if ($temAtivo && ($meta['ativo_significa'] ?? null) !== 'sem_limite') {
                    $validator->errors()->add("alteracoes.$i.ativo", "{$meta['rotulo']}: use o valor, não o interruptor de ativo.");
                }
            }

            $this->validarSomaDosPesos($validator);
        }];
    }
}

<?php

namespace App\Http\Requests\TipoRegistro;

use Illuminate\Validation\Validator;

/**
 * Recorte do campo Mix (SORTIMENTO dinâmico) — fixo no cadastro (sortimento_tipo_vinculo) ou
 * seguindo a escolha do promotor (sortimento_segue_escolha, docs/79 §4.2). Regra compartilhada
 * entre Store e UpdateTipoRegistroRequest.
 */
trait ValidaRecorteDoMix
{
    protected function validarRecorteDoMix(Validator $validator, bool $formularioTemEscolha): void
    {
        foreach (array_values($this->input('campos', [])) as $indice => $campo) {
            $segueEscolha = filter_var($campo['sortimento_segue_escolha'] ?? false, FILTER_VALIDATE_BOOLEAN);
            $origem = $campo['sortimento_origem'] ?? null;

            if ($segueEscolha) {
                if ($origem !== 'DINAMICO') {
                    $validator->errors()->add("campos.{$indice}.sortimento_segue_escolha", 'Só o mix real de cada loja pode seguir a escolha do promotor.');
                } elseif (! $formularioTemEscolha) {
                    $validator->errors()->add("campos.{$indice}.sortimento_segue_escolha", 'Pra seguir a escolha do promotor, o formulário precisa deixar ele escolher marca, departamento ou seção.');
                }

                continue;
            }

            // Sem recorte = o Mix inteiro de cada loja (verificação de mix). Com recorte, precisa do item.
            $tipoVinculo = $campo['sortimento_tipo_vinculo'] ?? null;
            $chaveItem = ['SECAO' => 'sortimento_secao_uuid', 'DEPARTAMENTO' => 'sortimento_departamento_uuid', 'MARCA' => 'sortimento_marca_uuid'][$tipoVinculo] ?? null;
            if ($origem === 'DINAMICO' && $chaveItem !== null && empty($campo[$chaveItem])) {
                $validator->errors()->add("campos.{$indice}.sortimento_tipo_vinculo", 'Escolha a seção, o departamento ou a marca do mix.');
            }
        }
    }
}

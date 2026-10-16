<?php

namespace App\Support;

use App\Enums\StatusAprovacao;
use App\Models\MarcaAuditoria;
use Illuminate\Support\Facades\DB;

/**
 * Junta uma marca (tipicamente a cadastrada pelo promotor, "DOWNY") em outra que já existe
 * ("Downy"), docs/85 §4.2: tudo que apontava pra origem passa a apontar pro destino, e a origem
 * fica inativa (REJEITADO) — nunca apagada, pra não perder rastro.
 */
class JuntarMarcas
{
    /** Tabelas com `marca_id` simples (sem unicidade envolvendo a marca). */
    private const REFERENCIAS = [
        ['visita_registros', 'marca_id'],
        ['acompanhamentos_registro', 'marca_id'],
        ['produtos_auditoria', 'marca_id'],
        ['campanha_itens', 'marca_id'],
        ['contrato_metas', 'marca_id'],
        ['sortimentos_ponto_venda', 'marca_id'],
        ['tipos_registro', 'escopo_marca_id'],
    ];

    public static function executar(MarcaAuditoria $origem, MarcaAuditoria $destino): void
    {
        DB::transaction(function () use ($origem, $destino): void {
            foreach (self::REFERENCIAS as [$tabela, $coluna]) {
                DB::table($tabela)->where($coluna, $origem->id)->update([$coluna => $destino->id]);
            }

            // Pivôs com unicidade: só move o que o destino ainda não tem; o resto cai junto.
            self::moverPivo('tipo_registro_marcas', 'tipo_registro_id', $origem, $destino);
            self::moverPivo('marcas_departamentos', 'departamento_id', $origem, $destino);

            $origem->update(['ativo' => false, 'status_aprovacao' => StatusAprovacao::REJEITADO]);
        });
    }

    private static function moverPivo(string $tabela, string $outra, MarcaAuditoria $origem, MarcaAuditoria $destino): void
    {
        $jaTem = DB::table($tabela)->where('marca_id', $destino->id)->pluck($outra);
        DB::table($tabela)->where('marca_id', $origem->id)->whereNotIn($outra, $jaTem)->update(['marca_id' => $destino->id]);
        DB::table($tabela)->where('marca_id', $origem->id)->delete();
    }
}

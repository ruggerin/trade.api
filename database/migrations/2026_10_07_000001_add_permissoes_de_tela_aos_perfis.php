<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Permissão por tela — docs/64-CONTROLE-DE-ACESSO-POR-TELA.md. As telas que passam a exigir
 * `tela.*` eram abertas a todo GESTOR; pra ninguém perder acesso no dia da troca, todo perfil
 * existente ganha todas as `tela.*`. A partir daqui o ADMIN tira o que quiser.
 *
 * Lista fixa aqui (e não Permissao::telas()) de propósito: uma tela nova no futuro não pode ser
 * dada a todo mundo por esta migração já rodada — ela decide o próprio padrão.
 */
return new class extends Migration
{
    private const TELAS = [
        'tela.operacao_dia',
        'tela.atividades',
        'tela.visitas',
        'tela.registros',
        'tela.ordens_servico',
        'tela.campanhas',
        'tela.relatorios',
        'tela.lojas',
        'tela.catalogo',
        'tela.formularios',
        'tela.configuracoes',
    ];

    public function up(): void
    {
        DB::table('perfis')->orderBy('id')->each(function (object $perfil): void {
            $atuais = json_decode($perfil->permissoes ?? '[]', true) ?: [];
            $novas = array_values(array_unique([...$atuais, ...self::TELAS]));
            DB::table('perfis')->where('id', $perfil->id)->update(['permissoes' => json_encode($novas)]);
        });

        // GESTOR sem perfil também via essas telas — ganha um perfil só com elas (por empresa).
        $empresas = DB::table('usuarios')->where('user_type', 'GESTOR')->whereNull('perfil_id')->distinct()->pluck('empresa_id');
        foreach ($empresas as $empresaId) {
            $perfilId = DB::table('perfis')->insertGetId([
                'empresa_id' => $empresaId,
                'nome' => 'Acesso às telas',
                'descricao' => 'Criado na troca para permissão por tela (doc 64): as telas que todo gestor via, sem ações.',
                'permissoes' => json_encode(self::TELAS),
                'ativo' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('usuarios')->where('empresa_id', $empresaId)->where('user_type', 'GESTOR')->whereNull('perfil_id')
                ->update(['perfil_id' => $perfilId]);
        }
    }

    public function down(): void
    {
        DB::table('perfis')->orderBy('id')->each(function (object $perfil): void {
            $atuais = json_decode($perfil->permissoes ?? '[]', true) ?: [];
            $restantes = array_values(array_diff($atuais, self::TELAS));
            DB::table('perfis')->where('id', $perfil->id)->update(['permissoes' => json_encode($restantes)]);
        });
    }
};

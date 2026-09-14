<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `momento` (ANTES/DEPOIS) era um campo fixo, hardcoded no app mobile, sem nenhuma lógica de
 * negócio por trás (só um badge na tela do registro) — redundante com o sistema de campos
 * customizados por TipoRegistro que já existe (CampoTipoRegistro, tipo_campo MULTIPLA_ESCOLHA
 * já cobre "escolher uma opção de uma lista fixa", ver 2026_09_08_000005). Migra o dado
 * existente pra um campo customizado "Marcação" (chave `marcacao`, opções ["Antes","Depois"])
 * em cada TipoRegistro que já tinha algum registro com `momento` preenchido, e remove a coluna
 * — mantém só um jeito de fazer isso no sistema, em vez de dois em paralelo.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->migrarMomentoParaCampoCustomizado();

        Schema::table('visita_registros', function (Blueprint $table): void {
            $table->dropColumn('momento');
        });
    }

    private function migrarMomentoParaCampoCustomizado(): void
    {
        $agora = now();

        $tipoRegistroIds = DB::table('visita_registros')
            ->whereNotNull('momento')
            ->distinct()
            ->pluck('tipo_registro_id');

        foreach ($tipoRegistroIds as $tipoRegistroId) {
            $campoId = DB::table('campos_tipo_registro')
                ->where('tipo_registro_id', $tipoRegistroId)
                ->where('chave', 'marcacao')
                ->value('id');

            if (! $campoId) {
                $proximaOrdem = (DB::table('campos_tipo_registro')
                    ->where('tipo_registro_id', $tipoRegistroId)
                    ->max('ordem') ?? -1) + 1;

                DB::table('campos_tipo_registro')->insert([
                    'uuid' => DB::raw('gen_random_uuid()'),
                    'tipo_registro_id' => $tipoRegistroId,
                    'chave' => 'marcacao',
                    'rotulo' => 'Marcação',
                    'tipo_campo' => 'MULTIPLA_ESCOLHA',
                    'opcoes' => json_encode(['Antes', 'Depois']),
                    'obrigatorio' => false,
                    'ordem' => $proximaOrdem,
                    'created_at' => $agora,
                    'updated_at' => $agora,
                ]);
            }

            DB::table('visita_registros')
                ->where('tipo_registro_id', $tipoRegistroId)
                ->whereNotNull('momento')
                ->orderBy('id')
                ->chunkById(500, function ($registros): void {
                    foreach ($registros as $registro) {
                        $valoresCampos = $registro->valores_campos ? json_decode((string) $registro->valores_campos, true) : [];
                        $valoresCampos['marcacao'] = $registro->momento === 'ANTES' ? 'Antes' : 'Depois';

                        DB::table('visita_registros')
                            ->where('id', $registro->id)
                            ->update(['valores_campos' => json_encode($valoresCampos)]);
                    }
                });
        }
    }

    public function down(): void
    {
        Schema::table('visita_registros', function (Blueprint $table): void {
            $table->string('momento', 10)->nullable();
        });
    }
};

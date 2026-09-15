<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Backfill: cada visita_registros.imagem_path preenchido vira 1 imagens_registro + 1
        // vínculo na pivot. Consulta direta (não Eloquent) — migration de dado não deve depender
        // da forma atual dos models, que já vai mudar assim que esta migration passar. Ver
        // docs/21-EVIDENCIA-EM-FOTOS.md §5.
        DB::table('visita_registros')
            ->whereNotNull('imagem_path')
            ->orderBy('id')
            ->select(['id', 'visita_id', 'imagem_path'])
            ->each(function (object $registro): void {
                // uuid não vai no insert — a coluna já tem default gen_random_uuid() no banco
                // (ver migration anterior).
                $imagemId = DB::table('imagens_registro')->insertGetId([
                    'visita_id' => $registro->visita_id,
                    'caminho' => $registro->imagem_path,
                    'created_at' => now(),
                ]);

                DB::table('visita_registro_imagem')->insert([
                    'visita_registro_id' => $registro->id,
                    'imagem_registro_id' => $imagemId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });

        // Sem manter coluna morta/shim de compatibilidade — o campo deixou de existir no model
        // (VisitaRegistro passa a usar a relação imagens()).
        Schema::table('visita_registros', function (Blueprint $table): void {
            $table->dropColumn('imagem_path');
        });
    }

    public function down(): void
    {
        Schema::table('visita_registros', function (Blueprint $table): void {
            $table->string('imagem_path')->nullable();
        });

        // Restaura só o primeiro vínculo de cada registro (suficiente pra um rollback de
        // desenvolvimento — não é uma migração reversível "perfeita", assim como a maioria das
        // migrations deste projeto que fazem backfill).
        DB::table('visita_registro_imagem')
            ->join('imagens_registro', 'imagens_registro.id', '=', 'visita_registro_imagem.imagem_registro_id')
            ->orderBy('visita_registro_imagem.id')
            ->select(['visita_registro_imagem.visita_registro_id', 'imagens_registro.caminho'])
            ->each(function (object $vinculo): void {
                DB::table('visita_registros')
                    ->where('id', $vinculo->visita_registro_id)
                    ->whereNull('imagem_path')
                    ->update(['imagem_path' => $vinculo->caminho]);
            });
    }
};

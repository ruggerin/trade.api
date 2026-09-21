<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Feedback em cima de um registro de visita (docs/28-RELATORIOS-FEEDBACK-HISTORICO.md §3): feed
 * cronológico de comentários entre promotor e admin/gestor, sem thread aninhada. A tabela de
 * leituras guarda o "visto até" de cada usuário por registro — é o que alimenta o badge de não
 * lidos por polling (sem push, decisão da proposta mínima).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('visita_registro_comentarios', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique()->default(DB::raw('gen_random_uuid()'));
            $table->foreignId('visita_registro_id')->constrained('visita_registros')->cascadeOnDelete();
            $table->foreignId('usuario_id')->constrained('usuarios');
            $table->text('texto');
            $table->timestamps();

            $table->index(['visita_registro_id', 'created_at']);
        });

        Schema::create('visita_registro_comentario_leituras', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('visita_registro_id')->constrained('visita_registros')->cascadeOnDelete();
            $table->foreignId('usuario_id')->constrained('usuarios');
            $table->timestamp('lido_em');

            $table->unique(['visita_registro_id', 'usuario_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('visita_registro_comentario_leituras');
        Schema::dropIfExists('visita_registro_comentarios');
    }
};

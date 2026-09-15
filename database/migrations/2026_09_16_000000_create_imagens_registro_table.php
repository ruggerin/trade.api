<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Entidade de foto própria — a relação real entre registro e foto é N:N (uma foto pode
        // evidenciar várias respostas, uma resposta pode ter várias fotos), ver
        // docs/21-EVIDENCIA-EM-FOTOS.md. Sem empresa_id/BelongsToEmpresa própria — tenant
        // resolvido via visita_id, mesmo padrão que VisitaRegistro já usa hoje.
        Schema::create('imagens_registro', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique()->default(DB::raw('gen_random_uuid()'));
            $table->foreignId('visita_id')->constrained('visitas')->cascadeOnDelete();
            $table->string('caminho');
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('imagens_registro');
    }
};

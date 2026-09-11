<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tipos_registro', function (Blueprint $table) {
            $table->boolean('acao_obrigatoria')->default(false);
            // Nullable — só preenchido quando acao_obrigatoria = true. String em vez de enum
            // nativo do banco, mesmo padrão do resto do projeto (cast pro enum PHP na aplicação).
            $table->string('escopo_acao')->nullable();
            $table->foreignId('campanha_auditoria_id')->nullable()->constrained('campanhas_auditoria')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tipos_registro', function (Blueprint $table) {
            $table->dropConstrainedForeignId('campanha_auditoria_id');
            $table->dropColumn(['acao_obrigatoria', 'escopo_acao']);
        });
    }
};

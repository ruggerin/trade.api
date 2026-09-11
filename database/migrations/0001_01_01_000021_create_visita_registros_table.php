<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('visita_registros', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique()->default(DB::raw('gen_random_uuid()'));
            $table->foreignId('visita_id')->constrained('visitas');
            $table->foreignId('produto_auditoria_id')->nullable()->constrained('produtos_auditoria');
            // Literal (não a classe App\Enums\TipoRegistroVisita, removida — ver
            // 2026_09_08_000006_replace_tipo_registro_enum_on_visita_registros_table.php) pra
            // esta migration antiga continuar recriando o histórico do zero sem depender de uma
            // classe que não existe mais.
            $table->enum('tipo_registro', ['FOTO', 'RUPTURA', 'OBSERVACAO']);
            $table->boolean('ruptura')->default(false);
            $table->text('observacao')->nullable();
            $table->text('imagem_path')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('visita_registros');
    }
};

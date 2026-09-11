<?php

use App\Enums\StatusVisita;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('visitas', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique()->default(DB::raw('gen_random_uuid()'));
            // Denormalizado direto (não só via ponto_venda_id/usuario_id) — filtra/indexa por
            // empresa sem join e blinda contra erro de isolamento, ver docs/01-MODELO-DE-DADOS.md.
            $table->foreignId('empresa_id')->constrained('empresas');
            $table->foreignId('ponto_venda_id')->constrained('pontos_venda');
            $table->foreignId('usuario_id')->constrained('usuarios');
            $table->foreignId('campanha_id')->nullable()->constrained('campanhas_auditoria');
            $table->enum('tipo', ['PROGRAMADA', 'NAO_PROGRAMADA']);
            $table->enum('status', array_column(StatusVisita::cases(), 'value'))
                ->default(StatusVisita::ABERTA->value);
            $table->timestamp('inicio_data');
            $table->double('inicio_latitude');
            $table->double('inicio_longitude');
            $table->double('inicio_distancia_metros');
            $table->timestamp('fim_data')->nullable();
            $table->double('fim_latitude')->nullable();
            $table->double('fim_longitude')->nullable();
            $table->double('fim_distancia_metros')->nullable();
            $table->timestamp('programacao_inicio')->nullable();
            $table->timestamp('programacao_fim')->nullable();
            $table->foreignId('programacao_usuario_id')->nullable()->constrained('usuarios');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('visitas');
    }
};

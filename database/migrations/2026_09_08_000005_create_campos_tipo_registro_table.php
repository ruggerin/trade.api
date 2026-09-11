<?php

use App\Enums\TipoCampoRegistro;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Definição dos campos extras de um TipoRegistro (ex.: "Quantidade" número, "Valor"
        // moeda) — gerenciados sempre junto do tipo pai, no mesmo formulário do admin web, por
        // isso sem controller/rota própria (ver TipoRegistroController::store/update, que
        // sincroniza esta tabela inteira a cada salvar). uuid mesmo sem endpoint dedicado, só
        // por consistência com o resto do sistema (nunca expor o id bigint interno).
        Schema::create('campos_tipo_registro', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique()->default(DB::raw('gen_random_uuid()'));
            $table->foreignId('tipo_registro_id')->constrained('tipos_registro')->cascadeOnDelete();
            $table->string('chave', 50);
            $table->string('rotulo');
            $table->enum('tipo_campo', array_column(TipoCampoRegistro::cases(), 'value'));
            // Só usado quando tipo_campo = MULTIPLA_ESCOLHA — lista de strings, ex.:
            // ["Boa", "Regular", "Ruim"].
            $table->jsonb('opcoes')->nullable();
            $table->boolean('obrigatorio')->default(false);
            $table->unsignedSmallInteger('ordem')->default(0);
            $table->timestamps();

            $table->unique(['tipo_registro_id', 'chave']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campos_tipo_registro');
    }
};

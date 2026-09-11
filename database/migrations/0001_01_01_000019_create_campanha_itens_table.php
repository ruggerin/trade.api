<?php

use App\Enums\TipoItemCampanha;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campanha_itens', function (Blueprint $table): void {
            $table->id();
            // Tem uuid (endpoint próprio de remoção) mas não empresa_id — herda o isolamento
            // de campanhas_auditoria via campanha_id.
            $table->uuid('uuid')->unique()->default(DB::raw('gen_random_uuid()'));
            $table->foreignId('campanha_id')->constrained('campanhas_auditoria');
            // Discriminador: diz qual das FKs abaixo é a relevante neste item. Era
            // `tipo_prod_auditoria` no sistema antigo, renomeado pra `tipo_item` por
            // consistência com o endpoint documentado em docs/02-API-BACKEND.md.
            $table->enum('tipo_item', array_column(TipoItemCampanha::cases(), 'value'));
            $table->foreignId('produto_id')->nullable()->constrained('produtos_auditoria');
            $table->foreignId('departamento_id')->nullable()->constrained('departamentos_auditoria');
            $table->foreignId('secao_id')->nullable()->constrained('secoes_auditoria');
            $table->foreignId('marca_id')->nullable()->constrained('marcas_auditoria');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campanha_itens');
    }
};

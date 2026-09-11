<?php

use App\Enums\CategoriaCentroCustoItem;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('centro_custo_itens', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique()->default(DB::raw('gen_random_uuid()'));
            $table->foreignId('centro_custo_id')->constrained('centros_custo')->cascadeOnDelete();
            $table->enum('categoria', array_column(CategoriaCentroCustoItem::cases(), 'value'));
            $table->string('descricao');
            $table->decimal('valor_mensal', 10, 2);
            $table->unsignedSmallInteger('ordem')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('centro_custo_itens');
    }
};

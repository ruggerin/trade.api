<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Um bloco só existe quando um produto é posicionado ali — posição "vazia" é a ausência
        // de bloco cobrindo aquele índice, não uma linha com produto_auditoria_id nulo. Ver
        // docs/22-PLANOGRAMA.md §3 (sem sobreposição/estouro validado em
        // App\Http\Requests\PlanogramaBloco, não dá pra expressar como constraint de banco
        // simples porque depende da largura variável de cada bloco).
        Schema::create('planograma_blocos', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique()->default(DB::raw('gen_random_uuid()'));
            $table->foreignId('prateleira_id')->constrained('planograma_prateleiras')->cascadeOnDelete();
            $table->unsignedSmallInteger('posicao_inicio');
            $table->unsignedSmallInteger('largura')->default(1);
            $table->foreignId('produto_auditoria_id')->constrained('produtos_auditoria');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('planograma_blocos');
    }
};

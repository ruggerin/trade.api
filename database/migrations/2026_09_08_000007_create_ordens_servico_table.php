<?php

use App\Enums\OrigemOrdemServico;
use App\Enums\StatusOrdemServico;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ordens_servico', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique()->default(DB::raw('gen_random_uuid()'));
            $table->foreignId('empresa_id')->constrained('empresas');
            $table->foreignId('ponto_venda_id')->constrained('pontos_venda');
            // NULL = fila aberta, qualquer promotor da empresa pode atender — ver
            // docs/07-ORDEM-DE-SERVICO.md.
            $table->foreignId('usuario_id')->nullable()->constrained('usuarios');
            $table->enum('origem', array_column(OrigemOrdemServico::cases(), 'value'));
            $table->foreignId('campanha_id')->nullable()->constrained('campanhas_auditoria');
            $table->boolean('obrigatoria')->default(true);
            $table->timestamp('prazo_inicio');
            $table->timestamp('prazo_fim');
            $table->enum('status', array_column(StatusOrdemServico::cases(), 'value'))
                ->default(StatusOrdemServico::PENDENTE->value);
            // Preenchida quando o promotor efetivamente inicia a visita a partir desta OS — uma
            // OS só pode "virar" no máximo uma visita.
            $table->foreignId('visita_id')->nullable()->unique()->constrained('visitas');
            $table->text('observacao')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ordens_servico');
    }
};

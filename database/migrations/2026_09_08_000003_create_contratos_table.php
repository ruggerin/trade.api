<?php

use App\Enums\TipoContrato;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Contrato entre a empresa e um ponto de venda (comodato de expositor, ponto extra) —
        // ver docs/01-MODELO-DE-DADOS.md. arquivo_path segue o mesmo padrão de
        // visita_registros.imagem_path: guardado em disco privado, servido por rota autenticada
        // própria (nunca por URL pública direta), ver ContratoController::arquivo.
        Schema::create('contratos', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique()->default(DB::raw('gen_random_uuid()'));
            $table->foreignId('empresa_id')->constrained('empresas');
            $table->foreignId('ponto_venda_id')->constrained('pontos_venda');
            $table->enum('tipo', array_column(TipoContrato::cases(), 'value'));
            $table->text('descricao')->nullable();
            $table->timestamp('vigencia_inicio');
            $table->timestamp('vigencia_fim');
            $table->text('arquivo_path')->nullable();
            $table->boolean('ativo')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contratos');
    }
};

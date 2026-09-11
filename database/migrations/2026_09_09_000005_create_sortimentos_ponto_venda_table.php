<?php

use App\Enums\StatusAprovacao;
use App\Enums\TipoItemCampanha;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sortimentos_ponto_venda', function (Blueprint $table): void {
            $table->id();
            // Tem uuid (endpoint próprio de remoção) mas não empresa_id — herda o isolamento de
            // PontoVenda via ponto_venda_id, mesmo raciocínio de campanha_itens.
            $table->uuid('uuid')->unique()->default(DB::raw('gen_random_uuid()'));
            $table->foreignId('ponto_venda_id')->constrained('pontos_venda');
            // Discriminador: diz qual das FKs abaixo é a relevante neste item — reaproveita o
            // mesmo enum de campanha_itens.
            $table->enum('tipo_item', array_column(TipoItemCampanha::cases(), 'value'));
            $table->foreignId('produto_id')->nullable()->constrained('produtos_auditoria');
            $table->foreignId('departamento_id')->nullable()->constrained('departamentos_auditoria');
            $table->foreignId('secao_id')->nullable()->constrained('secoes_auditoria');
            $table->foreignId('marca_id')->nullable()->constrained('marcas_auditoria');
            // NULL = adicionado por admin/gestor no admin web (sempre válido direto);
            // preenchido = adicionado por um promotor pela visita — ver docs/14, §9.
            $table->foreignId('usuario_id')->nullable()->constrained('usuarios');
            // Só PENDENTE é usado aqui (rejeitar apaga a linha, ver App\Enums\StatusAprovacao).
            $table->enum('status_aprovacao', array_column(StatusAprovacao::cases(), 'value'))->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sortimentos_ponto_venda');
    }
};

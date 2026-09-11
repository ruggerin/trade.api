<?php

use App\Enums\FontePagamentoMeta;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Meta de contrapartida comercial (verba de trade marketing) negociada dentro de um
        // Contrato — ver docs/09-CONTRATO-METAS.md. Sem empresa_id próprio: não é
        // BelongsToEmpresa, herda o isolamento via contrato_id (mesmo padrão de
        // CampanhaItem/CentroCustoItem — ver docs/09-CONTRATO-METAS.md §3).
        Schema::create('contrato_metas', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique()->default(DB::raw('gen_random_uuid()'));
            $table->foreignId('contrato_id')->constrained('contratos')->cascadeOnDelete();
            // NULL = meta geral do PDV, sem recorte por marca.
            $table->foreignId('marca_id')->nullable()->constrained('marcas_auditoria');
            $table->string('descricao')->nullable();
            $table->decimal('valor_investimento', 10, 2);
            // Vendas incrementais esperadas — não é "vendas totais", ver docs/09-CONTRATO-METAS.md §3.
            $table->decimal('meta_valor', 10, 2);
            $table->date('periodo_inicio');
            $table->date('periodo_fim');
            $table->enum('fonte_pagamento', array_column(FontePagamentoMeta::cases(), 'value'));
            // Só usado quando fonte_pagamento = COMPARTILHADO.
            $table->decimal('percentual_industria', 5, 2)->nullable();
            // NULL até alguém lançar manualmente (sem integração de ERP nesta fase, ver
            // docs/09-CONTRATO-METAS.md §2/§7).
            $table->decimal('resultado_apurado', 10, 2)->nullable();
            $table->timestamp('apurado_em')->nullable();
            $table->foreignId('apurado_por_usuario_id')->nullable()->constrained('usuarios');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contrato_metas');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Escopo opcional do plano (docs/37-PLANOS-DE-ACAO.md §13): uma loja, uma rede, ou nada.
        // Plano nascido de alerta herda a loja do alerta (preenchida pelo controller) — assim o
        // filtro por loja/rede da lista pega os dois tipos de plano igual.
        Schema::table('planos_acao', function (Blueprint $table): void {
            $table->foreignId('ponto_venda_id')->nullable()->after('origem_registro_id')->constrained('pontos_venda');
            $table->foreignId('rede_loja_id')->nullable()->after('ponto_venda_id')->constrained('redes_lojas');
            $table->index('ponto_venda_id');
            $table->index('rede_loja_id');
        });

        // Loja ou rede, nunca as duas — a loja já pertence a uma rede.
        DB::statement('ALTER TABLE planos_acao ADD CONSTRAINT planos_acao_loja_ou_rede CHECK (ponto_venda_id IS NULL OR rede_loja_id IS NULL)');

        // Planos de alerta já criados (fase 1) ganham a loja do alerta.
        DB::statement(
            'UPDATE planos_acao p SET ponto_venda_id = v.ponto_venda_id
             FROM visita_registros r JOIN visitas v ON v.id = r.visita_id
             WHERE r.id = p.origem_registro_id AND p.ponto_venda_id IS NULL'
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE planos_acao DROP CONSTRAINT IF EXISTS planos_acao_loja_ou_rede');

        Schema::table('planos_acao', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('rede_loja_id');
            $table->dropConstrainedForeignId('ponto_venda_id');
        });
    }
};

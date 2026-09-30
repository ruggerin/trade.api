<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Rota do dia (docs/48-ROTA-DO-DIA.md §4.1) — cada posição recebida, não só a última. As 3
        // colunas de "última posição" em `usuarios` continuam servindo o Mapa ao vivo. empresa_id
        // direto na linha pra limpeza por RASTREAMENTO_HISTORICO_DIAS sem join.
        Schema::create('localizacoes_historico', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->foreignId('usuario_id')->constrained('usuarios')->cascadeOnDelete();
            $table->double('latitude');
            $table->double('longitude');
            $table->timestamp('capturado_em');

            // Reenvio da mesma leitura (rede lenta) não duplica o ponto.
            $table->unique(['usuario_id', 'capturado_em']);
            $table->index(['empresa_id', 'capturado_em']);
        });

        // Linha do trajeto já encaixada nas ruas (Mapbox Map Matching, §4.4) — calculada uma vez por
        // promotor+dia; `assinatura` muda quando chegam pontos novos (o dia de hoje recalcula, dias
        // passados nunca mais chamam o serviço).
        Schema::create('rotas_dia', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('usuario_id')->constrained('usuarios')->cascadeOnDelete();
            $table->date('data');
            $table->string('assinatura', 64);
            $table->jsonb('linhas');
            $table->boolean('aproximada')->default(false);
            $table->timestamps();

            $table->unique(['usuario_id', 'data']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rotas_dia');
        Schema::dropIfExists('localizacoes_historico');
    }
};

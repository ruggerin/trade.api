<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Todos nullable — PDVs já cadastrados não têm esse dado, e o cadastro no admin não vira
        // bloqueante retroativo por causa disso (mesmo raciocínio de cnpj em
        // 2026_09_08_000002_add_cnpj_to_pontos_venda_table.php).
        Schema::table('pontos_venda', function (Blueprint $table): void {
            // nullOnDelete: desativar/apagar uma rede ou ramo não pode arrastar a loja junto —
            // ela só perde a classificação, continua existindo normalmente.
            $table->foreignId('rede_loja_id')->nullable()->after('empresa_id')->constrained('redes_lojas')->nullOnDelete();
            $table->foreignId('ramo_atividade_id')->nullable()->after('rede_loja_id')->constrained('ramos_atividade')->nullOnDelete();
            $table->unsignedSmallInteger('numero_checkouts')->nullable()->after('email');
            // Mesmo padrão de Usuario.foto_path / VisitaRegistro.imagem_path — caminho no disco
            // configurado (local ou s3, ver config('filesystems.default')), nunca a URL pública
            // direta, servido sempre pela rota autenticada (ver PontoVendaController::fachada).
            $table->string('fachada_path')->nullable()->after('numero_checkouts');
        });
    }

    public function down(): void
    {
        Schema::table('pontos_venda', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('rede_loja_id');
            $table->dropConstrainedForeignId('ramo_atividade_id');
            $table->dropColumn(['numero_checkouts', 'fachada_path']);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * docs/85 — concorrentes por marca e embalagem: granularidade MARCA (valor novo na coluna string
 * `granularidade_padrao`, sem mudança de tipo), lista de marcas do formulário, embalagem
 * estruturada no registro e marca cadastrada pelo promotor "a aprovar".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tipos_registro', function (Blueprint $table): void {
            // Promotor pode registrar marca fora da lista (entra "a aprovar").
            $table->boolean('permite_marca_nova')->default(true);
            // Mais de uma embalagem da mesma marca na visita (cada embalagem = um registro).
            $table->boolean('varias_embalagens')->default(true);
            // Granularidade PRODUTO: de onde vem a lista guiada (docs/85 §7). FIXA = a lista
            // montada no admin (tipo_registro_produtos); MIX_LOJA = os nossos produtos do Mix de
            // cada loja (checklist de presença), sem concorrente.
            $table->string('lista_produtos_origem', 10)->default('FIXA');
        });

        // Mesmo molde de tipo_registro_produtos: a ordem é a ordem no app.
        Schema::create('tipo_registro_marcas', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tipo_registro_id')->constrained('tipos_registro')->cascadeOnDelete();
            $table->foreignId('marca_id')->constrained('marcas_auditoria')->cascadeOnDelete();
            $table->unsignedInteger('ordem')->default(0);
            $table->timestamps();
            $table->unique(['tipo_registro_id', 'marca_id']);
        });

        Schema::table('visita_registros', function (Blueprint $table): void {
            // Embalagem estruturada (nunca texto livre): é o que permite comparar R$ por litro/kg.
            $table->decimal('embalagem_quantidade', 10, 3)->nullable();
            $table->string('embalagem_unidade', 3)->nullable();
        });

        Schema::table('marcas_auditoria', function (Blueprint $table): void {
            // Mesmo padrão de produtos_auditoria (migration 2026_09_09_000006): NULL = cadastro
            // normal; preenchido = o promotor criou pela visita.
            $table->foreignId('criado_por_usuario_id')->nullable()->constrained('usuarios')->nullOnDelete();
            // PENDENTE = "a aprovar"; REJEITADO = juntada em outra (fica inativa).
            $table->string('status_aprovacao', 10)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('marcas_auditoria', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('criado_por_usuario_id');
            $table->dropColumn('status_aprovacao');
        });
        Schema::table('visita_registros', function (Blueprint $table): void {
            $table->dropColumn(['embalagem_quantidade', 'embalagem_unidade']);
        });
        Schema::dropIfExists('tipo_registro_marcas');
        Schema::table('tipos_registro', function (Blueprint $table): void {
            $table->dropColumn(['permite_marca_nova', 'varias_embalagens', 'lista_produtos_origem']);
        });
    }
};

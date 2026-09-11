<?php

use App\Enums\StatusAprovacao;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('produtos_auditoria', function (Blueprint $table): void {
            // NULL = cadastro normal (admin/gestor); preenchido = criado por um promotor pela
            // visita ("ele mesmo cadastrar"), ver docs/14-SORTIMENTO-PONTO-VENDA.md §9.2.
            $table->foreignId('criado_por_usuario_id')->nullable()->after('empresa_id')->constrained('usuarios');
            // PENDENTE/REJEITADO só quando criado_por_usuario_id não é nulo e o modo era
            // REQUER_APROVACAO; NULL = não se aplica (aprovado ou nem passou por aprovação).
            // Diferente do sortimento, rejeitar aqui NÃO apaga a linha — o registro do promotor
            // já pode apontar pro produto_auditoria_id recém-criado.
            $table->enum('status_aprovacao', array_column(StatusAprovacao::cases(), 'value'))
                ->nullable()->after('ativo');
        });
    }

    public function down(): void
    {
        Schema::table('produtos_auditoria', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('criado_por_usuario_id');
            $table->dropColumn('status_aprovacao');
        });
    }
};

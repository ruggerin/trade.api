<?php

use App\Enums\StatusFatura;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('faturas', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique()->default(DB::raw('gen_random_uuid()'));
            $table->foreignId('empresa_id')->constrained('empresas');
            $table->decimal('valor', 10, 2);
            // Mês de competência (dia é convenção, sempre o 1º do mês) — não é a data de
            // vencimento.
            $table->date('referencia');
            $table->date('vencimento');
            $table->enum('status', array_column(StatusFatura::cases(), 'value'))
                ->default(StatusFatura::PENDENTE->value);
            $table->date('pago_em')->nullable();
            $table->text('observacao')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('faturas');
    }
};

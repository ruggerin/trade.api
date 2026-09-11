<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Puramente associativa: sem uuid nem empresa_id próprios (herda isolamento e
        // identificação de marcas_auditoria/departamentos_auditoria) — ver
        // docs/01-MODELO-DE-DADOS.md#identificador-público-uuid.
        Schema::create('marcas_departamentos', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('marca_id')->constrained('marcas_auditoria');
            $table->foreignId('departamento_id')->constrained('departamentos_auditoria');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marcas_departamentos');
    }
};

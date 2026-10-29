<?php

use App\Enums\TipoCampoRegistro;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fotos nomeadas no formulário (docs/98): pergunta do tipo FOTO, captura (hora e GPS) por foto e o
 * nome/origem de cada foto no vínculo com o registro.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Mesma pegadinha de 2026_09_18_000002: o CHECK de tipo_campo acompanha o enum.
        DB::statement('ALTER TABLE campos_tipo_registro DROP CONSTRAINT campos_tipo_registro_tipo_campo_check');
        $valores = "'".implode("', '", array_column(TipoCampoRegistro::cases(), 'value'))."'";
        DB::statement("ALTER TABLE campos_tipo_registro ADD CONSTRAINT campos_tipo_registro_tipo_campo_check CHECK (tipo_campo IN ({$valores}))");

        Schema::table('campos_tipo_registro', function (Blueprint $table): void {
            // Só valem com tipo_campo = FOTO. Nulo = sem limite.
            $table->unsignedSmallInteger('foto_min')->nullable();
            $table->unsignedSmallInteger('foto_max')->nullable();
            // NAO | VISITA (fotos desta visita) | HISTORICO (desta visita e de visitas anteriores na loja).
            $table->string('foto_reaproveitar', 10)->default('NAO');
            $table->string('foto_dica', 255)->nullable();
        });

        Schema::table('imagens_registro', function (Blueprint $table): void {
            // Captura no aparelho (galeria Horus, docs/98 §9.1). Nulo = foto de app antigo.
            $table->timestamp('capturada_em')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->unsignedInteger('precisao_metros')->nullable();
            // Distância até a loja da visita, calculada no servidor ao receber.
            $table->unsignedInteger('distancia_loja_metros')->nullable();
        });

        Schema::table('visita_registro_imagem', function (Blueprint $table): void {
            // Pergunta FOTO que esta foto responde (nula = foto livre do registro).
            $table->string('campo_chave', 50)->nullable();
            // CAMERA (tirada agora) | VISITA (reaproveitada desta visita) | HISTORICO (de visita anterior).
            $table->string('origem', 10)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('visita_registro_imagem', fn (Blueprint $t) => $t->dropColumn(['campo_chave', 'origem']));
        Schema::table('imagens_registro', fn (Blueprint $t) => $t->dropColumn(['capturada_em', 'latitude', 'longitude', 'precisao_metros', 'distancia_loja_metros']));
        Schema::table('campos_tipo_registro', fn (Blueprint $t) => $t->dropColumn(['foto_min', 'foto_max', 'foto_reaproveitar', 'foto_dica']));
        DB::statement('ALTER TABLE campos_tipo_registro DROP CONSTRAINT campos_tipo_registro_tipo_campo_check');
        $valores = "'".implode("', '", array_column(array_filter(TipoCampoRegistro::cases(), fn ($c) => $c !== TipoCampoRegistro::FOTO), 'value'))."'";
        DB::statement("ALTER TABLE campos_tipo_registro ADD CONSTRAINT campos_tipo_registro_tipo_campo_check CHECK (tipo_campo IN ({$valores}))");
    }
};

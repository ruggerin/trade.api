<?php

use App\Enums\TipoItemCampanha;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visita_registros', function (Blueprint $table): void {
            $table->foreignId('tipo_registro_id')->nullable()->constrained('tipos_registro');
            // Vínculo opcional a um recorte do catálogo mais amplo que um produto específico —
            // mesmo padrão de campanha_itens (tipo_item + as 3 FKs), ver
            // docs/01-MODELO-DE-DADOS.md. produto_auditoria_id já existia antes desta migração.
            $table->enum('tipo_vinculo', array_column(TipoItemCampanha::cases(), 'value'))->nullable();
            $table->foreignId('secao_id')->nullable()->constrained('secoes_auditoria');
            $table->foreignId('departamento_id')->nullable()->constrained('departamentos_auditoria');
            $table->foreignId('marca_id')->nullable()->constrained('marcas_auditoria');
            // Valores dos campos customizados do tipo_registro (ex.: {"quantidade": "5",
            // "valor": "199.90"}), chaveado por CampoTipoRegistro.chave.
            $table->jsonb('valores_campos')->nullable();
        });

        $this->criarTiposPadraoEBackfill();

        // doctrine/dbal não está instalado (Blueprint::change() não funciona sem ele) — SQL
        // direto pra tornar a coluna obrigatória depois do backfill acima.
        DB::statement('ALTER TABLE visita_registros ALTER COLUMN tipo_registro_id SET NOT NULL');

        Schema::table('visita_registros', function (Blueprint $table): void {
            $table->dropColumn('tipo_registro');
        });
    }

    /**
     * Cria os 3 tipos de registro "de fábrica" (Foto, Ruptura, Observação) pra cada empresa que
     * já tem visita registrada, e migra visita_registros.tipo_registro (enum antigo) pro novo
     * tipo_registro_id — ver docs/01-MODELO-DE-DADOS.md. Sem isso, o registro histórico ficaria
     * sem nenhum tipo depois da coluna antiga ser removida.
     */
    private function criarTiposPadraoEBackfill(): void
    {
        $agora = now();
        $empresaIds = DB::table('visitas')->distinct()->pluck('empresa_id');

        foreach ($empresaIds as $empresaId) {
            foreach ([
                ['chave_antiga' => 'FOTO', 'descricao' => 'Foto', 'exige_foto' => true],
                ['chave_antiga' => 'RUPTURA', 'descricao' => 'Ruptura', 'exige_foto' => false],
                ['chave_antiga' => 'OBSERVACAO', 'descricao' => 'Observação', 'exige_foto' => false],
            ] as $tipo) {
                $tipoRegistroId = DB::table('tipos_registro')->insertGetId([
                    'uuid' => DB::raw('gen_random_uuid()'),
                    'empresa_id' => $empresaId,
                    'descricao' => $tipo['descricao'],
                    'exige_foto' => $tipo['exige_foto'],
                    'permite_vincular_catalogo' => false,
                    'ativo' => true,
                    'created_at' => $agora,
                    'updated_at' => $agora,
                ]);

                DB::table('visita_registros')
                    ->join('visitas', 'visitas.id', '=', 'visita_registros.visita_id')
                    ->where('visitas.empresa_id', $empresaId)
                    ->where('visita_registros.tipo_registro', $tipo['chave_antiga'])
                    ->update(['visita_registros.tipo_registro_id' => $tipoRegistroId]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('visita_registros', function (Blueprint $table): void {
            $table->string('tipo_registro')->nullable();
        });

        Schema::table('visita_registros', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('tipo_registro_id');
            $table->dropColumn('tipo_vinculo');
            $table->dropConstrainedForeignId('secao_id');
            $table->dropConstrainedForeignId('departamento_id');
            $table->dropConstrainedForeignId('marca_id');
            $table->dropColumn('valores_campos');
        });
    }
};

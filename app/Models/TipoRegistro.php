<?php

namespace App\Models;

use App\Enums\EscopoAcaoTipoRegistro;
use App\Enums\GranularidadeResposta;
use App\Models\Concerns\BelongsToEmpresa;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TipoRegistro extends Model
{
    use BelongsToEmpresa, HasFactory, HasUuid;

    protected $table = 'tipos_registro';

    protected $fillable = [
        'empresa_id',
        'descricao',
        'icone',
        'ordem',
        'exige_foto',
        'permite_vincular_catalogo',
        'ativo',
        'acao_obrigatoria',
        'escopo_acao',
        'campanha_auditoria_id',
        'granularidade_padrao',
        'eh_ruptura',
    ];

    protected function casts(): array
    {
        return [
            'exige_foto' => 'boolean',
            'permite_vincular_catalogo' => 'boolean',
            'ativo' => 'boolean',
            'acao_obrigatoria' => 'boolean',
            'escopo_acao' => EscopoAcaoTipoRegistro::class,
            'granularidade_padrao' => GranularidadeResposta::class,
            'eh_ruptura' => 'boolean',
        ];
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    public function campos(): HasMany
    {
        return $this->hasMany(CampoTipoRegistro::class)->orderBy('ordem');
    }

    /**
     * Só preenchido quando `escopo_acao` é CAMPANHA — ver EscopoAcaoTipoRegistro.
     */
    public function campanhaAuditoria(): BelongsTo
    {
        return $this->belongsTo(CampanhaAuditoria::class);
    }

    /**
     * Exceções de granularidade por seção — sobrepõem `granularidade_padrao` só pra essa seção.
     * Ver App\Support\GranularidadeChecklist e docs/16-GRANULARIDADE-CHECKLIST-AUDITORIA.md §4.
     */
    public function excecoesGranularidade(): HasMany
    {
        return $this->hasMany(TipoRegistroSecaoExcecao::class);
    }

    /**
     * Cria os 3 tipos "de fábrica" (Foto, Ruptura, Observação) pra uma empresa nova — chamado
     * na criação da empresa (EmpresaController::signup/storeSuperadmin), mesmo texto/flags
     * usados no backfill histórico (ver migration
     * 2026_09_08_000006_replace_tipo_registro_enum_on_visita_registros_table.php).
     *
     * Diferente do resto do catálogo (departamentos, produtos etc.), que nasce vazio e o ADMIN
     * preenche — sem isso, uma empresa nova não teria NENHUM tipo cadastrado e o promotor não
     * conseguiria fazer nenhum registro em campo até alguém lembrar de cadastrar um tipo no
     * admin web. Continua 100% customizável depois (editar, desativar, criar outros).
     */
    public static function seedPadrao(int $empresaId): void
    {
        foreach ([
            ['descricao' => 'Foto', 'icone' => 'camera', 'exige_foto' => true, 'eh_ruptura' => false],
            // eh_ruptura=true + granularidade_padrao=PRODUTO — já nasce pronta pra ser a coluna
            // "Ruptura" da grade de coleta (Fase 2), sem o gestor precisar configurar nada.
            ['descricao' => 'Ruptura', 'icone' => 'package-variant-remove', 'exige_foto' => false, 'eh_ruptura' => true],
            ['descricao' => 'Observação', 'icone' => 'note-text-outline', 'exige_foto' => false, 'eh_ruptura' => false],
        ] as $ordem => $tipo) {
            self::create([
                'empresa_id' => $empresaId,
                'descricao' => $tipo['descricao'],
                'icone' => $tipo['icone'],
                'ordem' => $ordem,
                'exige_foto' => $tipo['exige_foto'],
                'permite_vincular_catalogo' => false,
                'eh_ruptura' => $tipo['eh_ruptura'],
                'granularidade_padrao' => $tipo['eh_ruptura'] ? 'PRODUTO' : null,
            ]);
        }
    }
}

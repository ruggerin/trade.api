<?php

namespace App\Models;

use App\Enums\EscopoAcaoTipoRegistro;
use App\Enums\GranularidadeResposta;
use App\Enums\TipoItemCampanha;
use App\Models\Concerns\BelongsToEmpresa;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
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
        // Recorte do escopo LINHA_AUSENTE (docs/80 §5.1) — NULL em qualquer outro escopo.
        'escopo_tipo_vinculo',
        'escopo_secao_id',
        'escopo_departamento_id',
        'escopo_marca_id',
        // Intervalo entre cobranças da Ação, em dias (docs/80 §5.2) — 0 = toda visita.
        'intervalo_dias',
        'granularidade_padrao',
        // Promotor escolhe o recorte no início do formulário — ver docs/79 e temEscolhaDeRecorte().
        'escolhe_marca',
        'escolhe_departamento',
        'escolhe_secao',
        'lista_recorte',
        'filtra_marca',
        // "Ainda está lá?" (docs/83 §5.1).
        'acompanha',
        'acompanha_gatilho_chave',
        'acompanha_gatilho_valor',
        'acompanha_intervalo_dias',
        'acompanha_rotulo_continua',
        'acompanha_rotulo_acabou',
        'acompanha_ao_continuar',
        'acompanha_formulario_acabou_id',
        'acompanha_prazo_dias',
        'eh_ruptura',
        'eh_alerta',
        'usa_pontuacao',
        'disponivel_registro_livre',
    ];

    protected function casts(): array
    {
        return [
            'exige_foto' => 'boolean',
            'permite_vincular_catalogo' => 'boolean',
            'ativo' => 'boolean',
            'acao_obrigatoria' => 'boolean',
            'escopo_acao' => EscopoAcaoTipoRegistro::class,
            'escopo_tipo_vinculo' => TipoItemCampanha::class,
            'intervalo_dias' => 'integer',
            'granularidade_padrao' => GranularidadeResposta::class,
            'escolhe_marca' => 'boolean',
            'escolhe_departamento' => 'boolean',
            'escolhe_secao' => 'boolean',
            'filtra_marca' => 'boolean',
            'acompanha' => 'boolean',
            'acompanha_intervalo_dias' => 'integer',
            'acompanha_prazo_dias' => 'integer',
            'eh_ruptura' => 'boolean',
            'eh_alerta' => 'boolean',
            'usa_pontuacao' => 'boolean',
            'disponivel_registro_livre' => 'boolean',
        ];
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    /**
     * Recortes que o promotor pode escolher no início do formulário (docs/79) — vazio = o
     * formulário não pede escolha (comportamento de antes).
     *
     * @return list<TipoItemCampanha>
     */
    public function recortesPermitidos(): array
    {
        return array_values(array_filter([
            $this->escolhe_marca ? TipoItemCampanha::MARCA : null,
            $this->escolhe_departamento ? TipoItemCampanha::DEPARTAMENTO : null,
            $this->escolhe_secao ? TipoItemCampanha::SECAO : null,
        ]));
    }

    /** docs/82 §5.1: lista "Na loja" primeiro (padrão) ou só o que a loja tem no Mix. */
    public const LISTA_PRIMEIRO_DA_LOJA = 'PRIMEIRO_DA_LOJA';

    public const LISTA_SO_DA_LOJA = 'SO_DA_LOJA';

    /** Formulário de justificativa ao responder "Acabou" (docs/83 §3.1.5). */
    public function formularioAcabou(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(self::class, 'acompanha_formulario_acabou_id');
    }

    public function soDaLoja(): bool
    {
        return $this->lista_recorte === self::LISTA_SO_DA_LOJA;
    }

    public function temEscolhaDeRecorte(): bool
    {
        return $this->recortesPermitidos() !== [];
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
     * Escopo LOJA_REDE (docs/40-ACAO-OBRIGATORIA-LOJA-REDE.md) — lojas específicas onde a Ação
     * obrigatória vale. Vazio em qualquer outro escopo (o controller limpa ao trocar).
     */
    public function pontosVenda(): BelongsToMany
    {
        return $this->belongsToMany(PontoVenda::class, 'tipo_registro_pontos_venda');
    }

    /**
     * Lista predefinida de produtos (granularidade PRODUTO) — com ela preenchida, o promotor só
     * vincula o registro a um destes, em qualquer loja (no mix ou não). Vazia = mix/campanha da
     * visita, como antes. Ordem = ordem em que o admin montou a lista.
     */
    public function produtosPredefinidos(): BelongsToMany
    {
        return $this->belongsToMany(ProdutoAuditoria::class, 'tipo_registro_produtos', 'tipo_registro_id', 'produto_auditoria_id')
            ->withPivot('ordem')
            ->orderByPivot('ordem');
    }

    /** Recorte do escopo LINHA_AUSENTE — preenchido conforme `escopo_tipo_vinculo`. */
    public function escopoSecao(): BelongsTo
    {
        return $this->belongsTo(SecaoAuditoria::class, 'escopo_secao_id');
    }

    public function escopoDepartamento(): BelongsTo
    {
        return $this->belongsTo(DepartamentoAuditoria::class, 'escopo_departamento_id');
    }

    public function escopoMarca(): BelongsTo
    {
        return $this->belongsTo(MarcaAuditoria::class, 'escopo_marca_id');
    }

    /**
     * Filtro da Ação por ramo de atividade da loja (docs/80 §5.4) — vale pra qualquer escopo.
     * Vazio = todos os ramos; loja sem ramo cadastrado passa.
     */
    public function ramosAtividade(): BelongsToMany
    {
        return $this->belongsToMany(RamoAtividade::class, 'tipo_registro_ramos_atividade');
    }

    /** Escopo LOJA_REDE — toda loja dessas redes também entra (OR com pontosVenda). */
    public function redesLojas(): BelongsToMany
    {
        return $this->belongsToMany(RedeLoja::class, 'tipo_registro_redes_lojas');
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

<?php

namespace App\Models;

use App\Enums\SortimentoOrigemCampo;
use App\Enums\TipoCampoRegistro;
use App\Enums\TipoItemCampanha;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Sem BelongsToEmpresa própria — herda isolamento de TipoRegistro via tipo_registro_id. Sem
 * controller/rota própria: gerenciado sempre junto do tipo pai, ver TipoRegistroController.
 */
class CampoTipoRegistro extends Model
{
    use HasFactory, HasUuid;

    protected $table = 'campos_tipo_registro';

    protected $fillable = [
        'tipo_registro_id', 'chave', 'rotulo', 'tipo_campo', 'opcoes', 'obrigatorio', 'ordem',
        'limite_dias_retroativos',
        'depende_de_campo_id', 'depende_de_valor',
        'sortimento_origem', 'sortimento_tipo_vinculo', 'sortimento_secao_id',
        'sortimento_departamento_id', 'sortimento_marca_id', 'confirmar_ruptura_ausentes',
    ];

    protected function casts(): array
    {
        return [
            'tipo_campo' => TipoCampoRegistro::class,
            'opcoes' => 'array',
            'obrigatorio' => 'boolean',
            'limite_dias_retroativos' => 'integer',
            'sortimento_origem' => SortimentoOrigemCampo::class,
            'sortimento_tipo_vinculo' => TipoItemCampanha::class,
            'confirmar_ruptura_ausentes' => 'boolean',
        ];
    }

    public function tipoRegistro(): BelongsTo
    {
        return $this->belongsTo(TipoRegistro::class);
    }

    /** Campo condicional (decisão 7 de docs/20-FORMULARIO-DINAMICO-CAMPANHA.md) — só preenchido quando este campo depende de outro do mesmo TipoRegistro. */
    public function dependeDe(): BelongsTo
    {
        return $this->belongsTo(self::class, 'depende_de_campo_id');
    }

    /** Só preenchido quando tipo_campo = SORTIMENTO e sortimento_tipo_vinculo = SECAO. */
    public function sortimentoSecao(): BelongsTo
    {
        return $this->belongsTo(SecaoAuditoria::class, 'sortimento_secao_id');
    }

    /** Só preenchido quando tipo_campo = SORTIMENTO e sortimento_tipo_vinculo = DEPARTAMENTO. */
    public function sortimentoDepartamento(): BelongsTo
    {
        return $this->belongsTo(DepartamentoAuditoria::class, 'sortimento_departamento_id');
    }

    /** Só preenchido quando tipo_campo = SORTIMENTO e sortimento_tipo_vinculo = MARCA. */
    public function sortimentoMarca(): BelongsTo
    {
        return $this->belongsTo(MarcaAuditoria::class, 'sortimento_marca_id');
    }

    /** Lista curada de produtos — só populada quando sortimento_origem = FIXO, ver App\Support\ResolverSortimentoCampo. */
    public function produtosFixos(): BelongsToMany
    {
        return $this->belongsToMany(ProdutoAuditoria::class, 'campo_tipo_registro_produtos', 'campo_tipo_registro_id', 'produto_auditoria_id');
    }
}

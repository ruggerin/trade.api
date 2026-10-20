<?php

namespace App\Models;

use App\Enums\TipoItemCampanha;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Um produto do mix da loja no dia da visita (docs/90 §3.1), gravado uma vez no check-out por
 * App\Support\MixDaVisita. Sem BelongsToEmpresa: herda o isolamento da visita.
 */
class VisitaMixItem extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'visita_mix_itens';

    protected $fillable = [
        'visita_id',
        'produto_id',
        'descricao',
        'marca_id',
        'marca_descricao',
        'secao_id',
        'secao_descricao',
        'departamento_id',
        'departamento_descricao',
        'propriedade',
        'produto_chave',
        'origem_tipo',
        'adicionado_na_visita',
    ];

    protected function casts(): array
    {
        return [
            'produto_chave' => 'boolean',
            'adicionado_na_visita' => 'boolean',
            'origem_tipo' => TipoItemCampanha::class,
        ];
    }

    public function visita(): BelongsTo
    {
        return $this->belongsTo(Visita::class);
    }

    public function produto(): BelongsTo
    {
        return $this->belongsTo(ProdutoAuditoria::class, 'produto_id');
    }
}

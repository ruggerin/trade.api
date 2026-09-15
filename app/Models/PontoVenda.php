<?php

namespace App\Models;

use App\Models\Concerns\BelongsToEmpresa;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PontoVenda extends Model
{
    use BelongsToEmpresa, HasFactory, HasUuid;

    protected $table = 'pontos_venda';

    protected $fillable = [
        'empresa_id',
        'rede_loja_id',
        'ramo_atividade_id',
        'codigo_externo',
        'cnpj',
        'razao_social',
        'fantasia',
        'latitude',
        'longitude',
        'endereco',
        'numero',
        'bairro',
        'cidade',
        'cep',
        'telefone',
        'email',
        'numero_checkouts',
        'fachada_path',
        'ativo',
    ];

    protected function casts(): array
    {
        return [
            'latitude' => 'double',
            'longitude' => 'double',
            'numero_checkouts' => 'integer',
            'ativo' => 'boolean',
        ];
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    public function redeLoja(): BelongsTo
    {
        return $this->belongsTo(RedeLoja::class);
    }

    public function ramoAtividade(): BelongsTo
    {
        return $this->belongsTo(RamoAtividade::class);
    }

    public function visitas(): HasMany
    {
        return $this->hasMany(Visita::class);
    }

    /**
     * Promotores que atendem esta loja — ver Usuario::pontosVenda e docs/02-API-BACKEND.md,
     * regra de negócio 6.
     */
    public function promotores(): BelongsToMany
    {
        return $this->belongsToMany(Usuario::class, 'promotor_pontos_venda', 'ponto_venda_id', 'usuario_id')
            ->withTimestamps();
    }

    public function ordensServico(): HasMany
    {
        return $this->hasMany(OrdemServico::class, 'ponto_venda_id');
    }

    /**
     * Sortimento de produtos desta loja — ver docs/14-SORTIMENTO-PONTO-VENDA.md.
     */
    public function sortimento(): HasMany
    {
        return $this->hasMany(SortimentoPontoVenda::class, 'ponto_venda_id');
    }

    /**
     * Contratos (comodato de expositor, ponto extra) desta loja — ver docs/09-CONTRATO-METAS.md.
     */
    public function contratos(): HasMany
    {
        return $this->hasMany(Contrato::class);
    }
}

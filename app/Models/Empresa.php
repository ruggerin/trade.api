<?php

namespace App\Models;

use App\Enums\PlanoEmpresa;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Empresa extends Model
{
    use HasFactory, HasUuid;

    protected $fillable = [
        'razao_social',
        'nome_fantasia',
        'cnpj',
        'plano',
        'limite_usuarios',
        'limite_pontos_venda',
        'limite_licencas',
        'ativo',
    ];

    protected function casts(): array
    {
        return [
            'plano' => PlanoEmpresa::class,
            'limite_usuarios' => 'integer',
            'limite_pontos_venda' => 'integer',
            'limite_licencas' => 'integer',
            'ativo' => 'boolean',
        ];
    }

    public function usuarios(): HasMany
    {
        return $this->hasMany(Usuario::class);
    }

    public function pontosVenda(): HasMany
    {
        return $this->hasMany(PontoVenda::class);
    }

    public function faturas(): HasMany
    {
        return $this->hasMany(Fatura::class);
    }
}

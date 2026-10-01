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
        // Módulo pago, só SUPERADMIN edita (docs/38-PEDIDO-VENDEDOR.md §12).
        'pedidos_venda_habilitado',
        'ativo',
        // docs/50 §4.3 — fuso (IANA) que define o corte de "dia" da empresa inteira.
        'fuso',
    ];

    protected function casts(): array
    {
        return [
            'plano' => PlanoEmpresa::class,
            'limite_usuarios' => 'integer',
            'limite_pontos_venda' => 'integer',
            'limite_licencas' => 'integer',
            'pedidos_venda_habilitado' => 'boolean',
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

<?php

namespace App\Models;

use App\Models\Concerns\BelongsToEmpresa;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Supervisor de vendas espelhado do ERP (docs/73). Entra por importação ou pelo integrador
 * (App\Support\EquipeComercial); a tela do admin só ajusta contato e o vínculo com usuário.
 */
class Supervisor extends Model
{
    use BelongsToEmpresa, HasUuid;

    protected $table = 'supervisores';

    protected $fillable = ['empresa_id', 'codigo_externo', 'nome', 'telefone', 'email', 'usuario_id', 'ativo'];

    protected function casts(): array
    {
        return ['ativo' => 'boolean'];
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class);
    }

    public function vendedores(): HasMany
    {
        return $this->hasMany(Vendedor::class);
    }
}

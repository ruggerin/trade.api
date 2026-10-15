<?php

namespace App\Models;

use App\Models\Concerns\BelongsToEmpresa;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Vendedor (RCA) espelhado do ERP (docs/73). Não é usuário: `usuario_id` só liga o cadastro ao
 * login quando ele também usa o app. `supervisor_id` vem do `codigo_supervisor` do ERP.
 */
class Vendedor extends Model
{
    use BelongsToEmpresa, HasUuid;

    protected $table = 'vendedores';

    protected $fillable = [
        'empresa_id', 'codigo_externo', 'nome', 'telefone', 'email', 'codigo_supervisor', 'supervisor_id', 'usuario_id', 'ativo',
    ];

    protected function casts(): array
    {
        return ['ativo' => 'boolean'];
    }

    public function supervisor(): BelongsTo
    {
        return $this->belongsTo(Supervisor::class);
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class);
    }

    /** Lojas da carteira deste vendedor (docs/73 §11.1). */
    public function pontosVenda(): BelongsToMany
    {
        return $this->belongsToMany(PontoVenda::class, 'vendedor_ponto_venda', 'vendedor_id', 'ponto_venda_id')
            ->withTimestamps();
    }
}

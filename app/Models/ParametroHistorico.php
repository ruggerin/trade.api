<?php

namespace App\Models;

use App\Models\Concerns\BelongsToEmpresa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Uma mudança numa configuração da empresa — ver App\Support\HistoricoParametros. Só inserção:
 * auditoria não se edita.
 */
class ParametroHistorico extends Model
{
    use BelongsToEmpresa;

    public const UPDATED_AT = null;

    protected $table = 'parametro_historico';

    protected $fillable = [
        'empresa_id', 'chave', 'valor_anterior', 'valor_novo', 'ativo_anterior', 'ativo_novo',
        'usuario_id', 'usuario_nome', 'origem',
    ];

    protected function casts(): array
    {
        return [
            'ativo_anterior' => 'boolean',
            'ativo_novo' => 'boolean',
            'created_at' => 'datetime',
        ];
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class);
    }
}

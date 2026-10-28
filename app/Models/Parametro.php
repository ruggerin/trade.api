<?php

namespace App\Models;

use App\Models\Concerns\BelongsToEmpresa;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Configuração genérica chave/valor por empresa — mobile e admin web baixam e guardam em
 * cache local, evitando requisição repetida. `valor` é sempre string; quem consome decide
 * como interpretar (int, bool, json) com base na convenção de cada `chave`.
 */
class Parametro extends Model
{
    use BelongsToEmpresa, HasFactory, HasUuid;

    protected $fillable = ['empresa_id', 'chave', 'valor', 'descricao', 'ativo'];

    protected function casts(): array
    {
        return ['ativo' => 'boolean'];
    }

    protected static function booted(): void
    {
        // A régua de XP guarda os parâmetros lidos durante a requisição (App\Support\Xp\ParametrosXp).
        static::saved(fn () => \App\Support\Xp\ParametrosXp::limparCache());
        static::deleted(fn () => \App\Support\Xp\ParametrosXp::limparCache());

        // Auditoria: toda mudança de valor ou de ligado/desligado (App\Support\HistoricoParametros).
        static::created(fn (self $p) => \App\Support\HistoricoParametros::registrar($p, 'created'));
        static::updated(fn (self $p) => \App\Support\HistoricoParametros::registrar($p, 'updated'));
        static::deleted(fn (self $p) => \App\Support\HistoricoParametros::registrar($p, 'deleted'));
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }
}

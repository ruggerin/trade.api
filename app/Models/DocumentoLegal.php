<?php

namespace App\Models;

use App\Enums\TipoDocumentoLegal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Versão publicada de um documento legal — docs/58 §4.1. Global (sem BelongsToEmpresa) e
 * imutável depois de publicada: o texto aceito por alguém nunca muda por baixo do aceite.
 */
class DocumentoLegal extends Model
{
    protected $table = 'documentos_legais';

    protected $fillable = [
        'tipo',
        'versao',
        'conteudo',
        'hash_sha256',
        'publicado_em',
    ];

    protected function casts(): array
    {
        return [
            'tipo' => TipoDocumentoLegal::class,
            'publicado_em' => 'datetime',
        ];
    }

    /** Já passou a valer — a vigente de um tipo é a mais recente destas. */
    public function scopePublicado(Builder $query): Builder
    {
        return $query->where('publicado_em', '<=', now());
    }

    public static function vigente(TipoDocumentoLegal $tipo): ?self
    {
        return self::query()
            ->where('tipo', $tipo)
            ->publicado()
            ->orderByDesc('publicado_em')
            ->orderByDesc('id')
            ->first();
    }

    public function aceites(): HasMany
    {
        return $this->hasMany(AceiteDocumentoLegal::class);
    }
}

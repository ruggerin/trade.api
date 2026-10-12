<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Aparelho vinculado a um usuário no app — histórico (docs/67 §4.2): cada vínculo é uma linha, a
 * ativa é a com `desvinculado_em` nulo (no máximo 1 por usuário, índice único parcial). O
 * identificador do aparelho só existe como hash SHA-256. Regras de vínculo/troca em
 * App\Support\VinculoAparelho.
 *
 * Sem BelongsToEmpresa de propósito: é lido no login, antes de existir usuário autenticado, e
 * sempre a partir de um Usuario já resolvido.
 */
class Dispositivo extends Model
{
    use HasUuid;

    public const ORIGEM_PRIMEIRO = 'PRIMEIRO';

    public const ORIGEM_TROCA_PROPRIA = 'TROCA_PROPRIA';

    public const ORIGEM_LIBERADO_ADMIN = 'LIBERADO_ADMIN';

    protected $fillable = [
        'usuario_id', 'empresa_id', 'identificador_hash', 'nome', 'plataforma', 'versao_so', 'versao_app',
        'origem', 'chave_publica', 'integridade_status', 'integridade_detalhes', 'ultimo_acesso_em',
        'vinculado_em', 'desvinculado_em', 'desvinculado_por', 'motivo',
    ];

    protected function casts(): array
    {
        return [
            'ultimo_acesso_em' => 'datetime',
            'vinculado_em' => 'datetime',
            'desvinculado_em' => 'datetime',
            'integridade_detalhes' => 'array',
        ];
    }

    public static function hashIdentificador(string $identificador): string
    {
        return hash('sha256', $identificador);
    }

    public function scopeAtivo(Builder $query): void
    {
        $query->whereNull('desvinculado_em');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class);
    }
}

<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Trilha append-only de segurança (docs/67 §4.2): login, troca/bloqueio de aparelho, sessão
 * derrubada, token reaproveitado, falha de integridade, anomalia de localização. Alimenta a tela de
 * licenças do ADMIN e serve de evidência contratual (docs/67 §5.2). Gravado só por
 * App\Support\Seguranca::registrar.
 *
 * Sem BelongsToEmpresa: boa parte nasce no login, sem usuário autenticado; quem lista filtra
 * empresa_id explicitamente.
 */
class EventoSeguranca extends Model
{
    use HasUuid;

    public const UPDATED_AT = null;

    protected $table = 'eventos_seguranca';

    protected $fillable = ['empresa_id', 'usuario_id', 'dispositivo_id', 'tipo', 'detalhes', 'ip', 'user_agent'];

    protected function casts(): array
    {
        return ['detalhes' => 'array'];
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class);
    }

    public function dispositivo(): BelongsTo
    {
        return $this->belongsTo(Dispositivo::class);
    }
}

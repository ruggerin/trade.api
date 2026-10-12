<?php

namespace App\Models;

use App\Models\Concerns\BelongsToEmpresa;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pedido de liberação de aparelho novo (docs/67 §4.3): nasce no login negado com 423
 * APARELHO_BLOQUEADO; o ADMIN aprova (o aparelho atual é desvinculado e só este pode entrar) ou
 * recusa pela tela de licenças.
 */
class SolicitacaoAparelho extends Model
{
    use BelongsToEmpresa, HasUuid;

    public const PENDENTE = 'PENDENTE';

    public const APROVADA = 'APROVADA';

    public const RECUSADA = 'RECUSADA';

    public const USADA = 'USADA';

    protected $table = 'solicitacoes_aparelho';

    protected $fillable = ['empresa_id', 'usuario_id', 'identificador_hash', 'nome', 'plataforma', 'status', 'decidido_por', 'decidido_em'];

    protected function casts(): array
    {
        return ['decidido_em' => 'datetime'];
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class);
    }
}

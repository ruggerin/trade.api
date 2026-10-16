<?php

namespace App\Models;

use App\Enums\Propriedade;
use App\Enums\StatusAprovacao;
use App\Models\Concerns\BelongsToEmpresa;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class MarcaAuditoria extends Model
{
    use BelongsToEmpresa, HasFactory, HasUuid;

    protected $table = 'marcas_auditoria';

    protected $fillable = ['empresa_id', 'descricao', 'propriedade', 'ativo', 'criado_por_usuario_id', 'status_aprovacao'];

    protected function casts(): array
    {
        return [
            'propriedade' => Propriedade::class,
            'ativo' => 'boolean',
            'status_aprovacao' => StatusAprovacao::class,
        ];
    }

    /** Promotor que cadastrou pela visita (docs/85 §4.1) — null no cadastro normal. */
    public function criadoPor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'criado_por_usuario_id');
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    public function departamentos(): BelongsToMany
    {
        return $this->belongsToMany(DepartamentoAuditoria::class, 'marcas_departamentos', 'marca_id', 'departamento_id');
    }
}

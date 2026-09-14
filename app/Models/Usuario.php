<?php

namespace App\Models;

use App\Enums\UserType;
use App\Models\Concerns\BelongsToEmpresa;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

class Usuario extends Authenticatable
{
    use BelongsToEmpresa, HasApiTokens, HasFactory, HasUuid;

    protected $fillable = [
        'empresa_id',
        'nome',
        'email',
        'senha_hash',
        'user_type',
        'perfil_id',
        'centro_custo_id',
        'ativo',
        'avatar_url',
        'foto_path',
    ];

    protected $hidden = [
        'senha_hash',
    ];

    protected function casts(): array
    {
        return [
            'user_type' => UserType::class,
            'ativo' => 'boolean',
        ];
    }

    /**
     * Coluna de senha própria (senha_hash), não a `password` padrão do Laravel.
     */
    public function getAuthPassword(): string
    {
        return $this->senha_hash;
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    /**
     * Rege permissões de escrita no admin web quando user_type = GESTOR (ver EnsurePermissao),
     * ou visibilidade no mobile quando user_type = PROMOTOR (ex.: "ver todos os PDVs", ver
     * docs/12-VISIBILIDADE-PONTOS-DE-VENDA.md) — os dois usos são independentes, um mesmo
     * catálogo de Permissao serve pros dois porque EnsurePermissao só consulta o perfil quando
     * o usuário é GESTOR; pra PROMOTOR ele nunca é usado pra liberar rota de admin. ADMIN
     * sempre tem acesso total, SUPERADMIN não usa perfil.
     */
    public function perfil(): BelongsTo
    {
        return $this->belongsTo(Perfil::class);
    }

    /**
     * Só tem efeito prático pra user_type = PROMOTOR — ADMIN/GESTOR/SUPERADMIN não são
     * custeados por este mecanismo. Ver docs/08-CENTRO-DE-CUSTO.md.
     */
    public function centroCusto(): BelongsTo
    {
        return $this->belongsTo(CentroCusto::class);
    }

    /**
     * Só tem valor prático pra PROMOTOR — trava de 1 dispositivo por vez, ver
     * AuthController::login. ADMIN/GESTOR/SUPERADMIN nunca têm um registro aqui.
     */
    public function dispositivo(): HasOne
    {
        return $this->hasOne(Dispositivo::class);
    }

    /**
     * Lojas que este promotor atende — restringe o que ele vê no mobile e onde pode fazer
     * check-in (ver docs/02-API-BACKEND.md, regra de negócio 6). Só tem efeito prático pra
     * user_type PROMOTOR; nada impede tecnicamente vincular outro tipo, mas os endpoints de
     * sincronização validam isso.
     */
    public function pontosVenda(): BelongsToMany
    {
        return $this->belongsToMany(PontoVenda::class, 'promotor_pontos_venda', 'usuario_id', 'ponto_venda_id')
            ->withTimestamps();
    }

    public function visitas(): HasMany
    {
        return $this->hasMany(Visita::class, 'usuario_id');
    }

    public function loginLogs(): HasMany
    {
        return $this->hasMany(UsuarioLoginLog::class);
    }
}

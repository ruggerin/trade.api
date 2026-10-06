<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Usuario
 */
class UsuarioResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'nome' => $this->nome,
            'email' => $this->email,
            'codigo_externo' => $this->codigo_externo,
            'user_type' => $this->user_type,
            'ativo' => $this->ativo,
            'avatar_url' => $this->avatar_url,
            // Foto enviada pelo próprio usuário (AuthController::atualizarFoto) ou por um
            // ADMIN/GESTOR pela tela de usuários (UsuarioController::atualizarFoto) — separado
            // de avatar_url (texto livre do admin web) de propósito, nunca mistura os dois.
            // Rota autenticada, mesmo padrão de VisitaRegistroController::imagem. `?v=` muda a
            // cada `update()` do usuário (não só foto) — inofensivo (só gera um refetch a mais
            // do blob em componentes como UsuarioAvatar), mas garante que trocar a foto invalida
            // o cache do browser mesmo a URL sendo sempre a mesma rota.
            'foto_url' => $this->foto_path ? url("/api/usuarios/{$this->uuid}/foto") . '?v=' . $this->updated_at?->timestamp : null,
            // SUPERADMIN não pertence a nenhuma empresa — empresa_id é null nesse caso.
            'empresa' => $this->whenLoaded('empresa', fn () => new EmpresaResource($this->empresa)),
            // GESTOR (permissões de admin) ou PROMOTOR (visibilidade no mobile) — ver
            // App\Models\Usuario::perfil().
            'perfil' => $this->whenLoaded(
                'perfil',
                fn () => $this->perfil ? [
                    'id' => $this->perfil->uuid,
                    'nome' => $this->perfil->nome,
                    // O mobile decide o que mostrar por isso — ex.: aba "Pedidos" só com
                    // pedidos_venda.criar (docs/38-PEDIDO-VENDEDOR.md §4). Perfil inativo = nada.
                    'permissoes' => $this->perfil->ativo ? ($this->perfil->permissoes ?? []) : [],
                ] : null,
            ),
            // Só tem valor pra PROMOTOR — nome do perfil de custo, nunca os valores (que exigem
            // a permissão centros_custo.gerenciar, ver docs/08-CENTRO-DE-CUSTO.md).
            'centro_custo' => $this->whenLoaded(
                'centroCusto',
                fn () => $this->centroCusto ? ['id' => $this->centroCusto->uuid, 'descricao' => $this->centroCusto->descricao] : null,
            ),
            // Só tem valor pra PROMOTOR — trava de 1 dispositivo por vez, ver
            // AuthController::login. null quando nunca logou pelo mobile.
            'dispositivo' => $this->whenLoaded(
                'dispositivo',
                fn () => $this->dispositivo ? [
                    'identificador' => $this->dispositivo->identificador,
                    'nome' => $this->dispositivo->nome,
                    'ultimo_acesso_em' => $this->dispositivo->ultimo_acesso_em,
                ] : null,
            ),
            // Uso do sistema (docs/52 §4.1): último acesso por app, dias ativos em 30, "sumiu".
            // Só nas telas de usuários (App\Support\Adesao::anexar).
            'acesso' => $this->when($this->resource->acessoResumo !== null, fn () => $this->resource->acessoResumo),
            // Termos/Política pendentes de aceite (docs/58 §5.3) — só no login, /auth/me e aceite.
            'documentos_pendentes' => $this->when(
                $this->resource->documentosPendentes !== null,
                fn () => $this->resource->documentosPendentes,
            ),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}

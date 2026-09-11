<?php

namespace Tests\Feature;

use App\Enums\Permissao;
use App\Models\Empresa;
use App\Models\Perfil;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PerfilTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_cria_perfil_com_permissoes(): void
    {
        $empresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/perfis', [
            'nome' => 'Gestor Regional',
            'permissoes' => [Permissao::PONTOS_VENDA_GERENCIAR->value, Permissao::CATALOGO_GERENCIAR->value],
        ]);

        $response->assertCreated()->assertJsonPath('perfil.nome', 'Gestor Regional');
        $this->assertDatabaseHas('perfis', ['nome' => 'Gestor Regional', 'empresa_id' => $empresa->id]);
    }

    public function test_rejeita_permissao_fora_do_catalogo_fixo(): void
    {
        $empresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($admin);

        $this->postJson('/api/perfis', [
            'nome' => 'Perfil Inválido',
            'permissoes' => ['permissao.inventada'],
        ])->assertStatus(422)->assertJsonValidationErrors('permissoes.0');
    }

    public function test_desativar_perfil_tira_a_permissao_do_gestor_imediatamente(): void
    {
        $empresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        $perfil = Perfil::factory()->comPermissoes([Permissao::PONTOS_VENDA_GERENCIAR->value])
            ->create(['empresa_id' => $empresa->id]);
        $gestor = Usuario::factory()->gestor()->create(['empresa_id' => $empresa->id, 'perfil_id' => $perfil->id]);

        Sanctum::actingAs($admin);
        $this->deleteJson("/api/perfis/{$perfil->uuid}")->assertNoContent();

        Sanctum::actingAs($gestor);
        $this->postJson('/api/pontos-venda', [
            'razao_social' => 'Loja LTDA',
            'fantasia' => 'Loja',
            'latitude' => -3.10,
            'longitude' => -60.02,
            'endereco' => 'Rua Z, 1',
            'cidade' => 'Manaus',
        ])->assertForbidden();
    }

    public function test_gestor_nunca_acessa_rota_de_perfis_mesmo_com_permissao_no_perfil(): void
    {
        $empresa = Empresa::factory()->create();
        // usuarios.gerenciar não é o que protege /perfis — a rota exige user_type:ADMIN exato,
        // então nenhuma combinação de permissão no perfil libera um GESTOR aqui.
        $perfil = Perfil::factory()->comPermissoes(array_column(Permissao::cases(), 'value'))
            ->create(['empresa_id' => $empresa->id]);
        $gestor = Usuario::factory()->gestor()->create(['empresa_id' => $empresa->id, 'perfil_id' => $perfil->id]);
        Sanctum::actingAs($gestor);

        $this->postJson('/api/perfis', ['nome' => 'Tentativa'])->assertForbidden();
    }
}

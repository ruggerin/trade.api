<?php

namespace Tests\Feature;

use App\Models\Empresa;
use App\Models\PontoVenda;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * docs/02-API-BACKEND.md, regra de negócio 4 — limite de usuários/PDVs do plano da empresa.
 * null = sem limite (plano sem teto).
 */
class PlanLimiteTest extends TestCase
{
    use RefreshDatabase;

    public function test_bloqueia_criar_usuario_alem_do_limite_do_plano(): void
    {
        $empresa = Empresa::factory()->create(['limite_usuarios' => 2]);
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        // admin conta 1, mais 1 promotor já bate no limite de 2.
        Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);

        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/usuarios', [
            'nome' => 'Terceiro Usuário',
            'email' => 'terceiro@empresa.com',
            'senha' => 'senha12345',
            'user_type' => 'PROMOTOR',
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseMissing('usuarios', ['email' => 'terceiro@empresa.com']);
    }

    public function test_permite_criar_usuario_quando_empresa_sem_limite(): void
    {
        $empresa = Empresa::factory()->semLimite()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        Usuario::factory()->promotor()->count(5)->create(['empresa_id' => $empresa->id]);

        Sanctum::actingAs($admin);

        $this->postJson('/api/usuarios', [
            'nome' => 'Mais Um',
            'email' => 'maisum@empresa.com',
            'senha' => 'senha12345',
            'user_type' => 'PROMOTOR',
        ])->assertCreated();
    }

    public function test_bloqueia_criar_ponto_de_venda_alem_do_limite_do_plano(): void
    {
        $empresa = Empresa::factory()->create(['limite_pontos_venda' => 1]);
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        PontoVenda::factory()->create(['empresa_id' => $empresa->id]);

        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/pontos-venda', [
            'razao_social' => 'Segunda Loja LTDA',
            'fantasia' => 'Segunda Loja',
            'latitude' => -3.10,
            'longitude' => -60.02,
            'endereco' => 'Rua X, 1',
            'cidade' => 'Manaus',
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseMissing('pontos_venda', ['fantasia' => 'Segunda Loja']);
    }

    public function test_limite_de_usuarios_e_contado_por_empresa_nao_globalmente(): void
    {
        $empresaCheia = Empresa::factory()->create(['limite_usuarios' => 1]);
        Usuario::factory()->admin()->create(['empresa_id' => $empresaCheia->id]);

        $empresaComVaga = Empresa::factory()->create(['limite_usuarios' => 2]);
        $adminComVaga = Usuario::factory()->admin()->create(['empresa_id' => $empresaComVaga->id]);

        Sanctum::actingAs($adminComVaga);

        // A empresa cheia já bateu o limite dela, mas isso não afeta a empresa do admin autenticado.
        $this->postJson('/api/usuarios', [
            'nome' => 'Novo',
            'email' => 'novo@outraempresa.com',
            'senha' => 'senha12345',
            'user_type' => 'PROMOTOR',
        ])->assertCreated();
    }
}

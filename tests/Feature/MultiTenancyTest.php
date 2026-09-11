<?php

namespace Tests\Feature;

use App\Models\Empresa;
use App\Models\PontoVenda;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * docs/02-API-BACKEND.md#multi-tenancy-e-isolamento-de-dados — global scope por empresa_id
 * (BelongsToEmpresa) tem que valer pra qualquer user_type que não seja SUPERADMIN, em toda
 * tabela tenant-aware. Aqui cobre só pontos_venda como representante do padrão (o mesmo trait
 * é usado em Usuario, Visita, Perfil, catálogo inteiro).
 */
class MultiTenancyTest extends TestCase
{
    use RefreshDatabase;

    public function test_usuario_nao_ve_pontos_de_venda_de_outra_empresa_na_listagem(): void
    {
        $empresaA = Empresa::factory()->create();
        $empresaB = Empresa::factory()->create();

        $pdvDaMinhaEmpresa = PontoVenda::factory()->create(['empresa_id' => $empresaA->id]);
        PontoVenda::factory()->create(['empresa_id' => $empresaB->id]);

        $usuario = Usuario::factory()->admin()->create(['empresa_id' => $empresaA->id]);
        Sanctum::actingAs($usuario);

        $response = $this->getJson('/api/pontos-venda')->assertOk();

        $ids = collect($response->json('pontos_venda'))->pluck('id');

        $this->assertTrue($ids->contains($pdvDaMinhaEmpresa->uuid));
        $this->assertCount(1, $ids);
    }

    public function test_usuario_nao_consegue_acessar_ponto_de_venda_de_outra_empresa_por_uuid(): void
    {
        $empresaA = Empresa::factory()->create();
        $empresaB = Empresa::factory()->create();

        $pdvDeOutraEmpresa = PontoVenda::factory()->create(['empresa_id' => $empresaB->id]);

        $usuario = Usuario::factory()->admin()->create(['empresa_id' => $empresaA->id]);
        Sanctum::actingAs($usuario);

        // Route model binding por uuid + global scope: o registro existe, mas não pertence à
        // empresa do usuário autenticado — precisa dar 404, nunca vazar que o uuid existe.
        $this->getJson("/api/pontos-venda/{$pdvDeOutraEmpresa->uuid}")->assertNotFound();
    }

    public function test_uuid_mal_formado_em_rota_com_route_model_binding_retorna_404_nao_500(): void
    {
        // Coluna `uuid` é do tipo nativo do Postgres — uma string que não é um uuid de verdade
        // (não é "não existe", é "nem chega a ser um uuid") faz o próprio banco rejeitar o
        // cast antes do Eloquent conseguir dizer "não encontrado". Ver bootstrap/app.php.
        $empresa = Empresa::factory()->create();
        $usuario = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($usuario);

        $this->getJson('/api/pontos-venda/isso-nao-e-um-uuid')->assertNotFound();
    }

    public function test_create_de_ponto_de_venda_injeta_empresa_id_do_usuario_autenticado_automaticamente(): void
    {
        $empresa = Empresa::factory()->create();
        $usuario = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($usuario);

        $this->postJson('/api/pontos-venda', [
            'razao_social' => 'Loja Teste LTDA',
            'fantasia' => 'Loja Teste',
            'latitude' => -3.10,
            'longitude' => -60.02,
            'endereco' => 'Rua Teste, 123',
            'cidade' => 'Manaus',
        ])->assertCreated();

        $this->assertDatabaseHas('pontos_venda', [
            'fantasia' => 'Loja Teste',
            'empresa_id' => $empresa->id,
        ]);
    }
}

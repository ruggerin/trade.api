<?php

namespace Tests\Feature;

use App\Models\CentroCusto;
use App\Models\Empresa;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * docs/08-CENTRO-DE-CUSTO.md — cadastro do custo/hora do promotor. Diferente do resto do
 * catálogo, a leitura também exige a permissão `centros_custo.gerenciar` (dado financeiro).
 */
class CentroCustoTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_cria_centro_de_custo_com_itens(): void
    {
        $empresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/centros-custo', [
            'descricao' => 'Promotor Padrão',
            'carga_horaria_semanal' => 44,
            'itens' => [
                ['categoria' => 'INDIVIDUAL', 'descricao' => 'Salário', 'valor_mensal' => 2000],
                ['categoria' => 'INDIVIDUAL', 'descricao' => 'Vale-transporte', 'valor_mensal' => 200],
                ['categoria' => 'GERAL', 'descricao' => 'Sistema', 'valor_mensal' => 100],
            ],
        ]);

        $response->assertCreated()
            ->assertJsonPath('centro_custo.descricao', 'Promotor Padrão')
            ->assertJsonCount(3, 'centro_custo.itens')
            ->assertJsonPath('centro_custo.itens.0.descricao', 'Salário');
    }

    public function test_leitura_tambem_exige_permissao_por_ser_dado_financeiro(): void
    {
        $empresa = Empresa::factory()->create();
        $gestor = Usuario::factory()->gestor()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($gestor);

        $this->getJson('/api/centros-custo')->assertForbidden();
    }

    public function test_gestor_sem_permissao_e_bloqueado_na_escrita(): void
    {
        $empresa = Empresa::factory()->create();
        $gestor = Usuario::factory()->gestor()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($gestor);

        $this->postJson('/api/centros-custo', ['descricao' => 'X', 'carga_horaria_semanal' => 44])
            ->assertForbidden();
    }

    public function test_update_substitui_a_lista_de_itens_inteira(): void
    {
        $empresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        $centroCusto = CentroCusto::factory()->create(['empresa_id' => $empresa->id]);
        \App\Models\CentroCustoItem::create([
            'centro_custo_id' => $centroCusto->id, 'categoria' => 'INDIVIDUAL',
            'descricao' => 'Antigo', 'valor_mensal' => 500, 'ordem' => 0,
        ]);
        Sanctum::actingAs($admin);

        $response = $this->putJson("/api/centros-custo/{$centroCusto->uuid}", [
            'itens' => [
                ['categoria' => 'GERAL', 'descricao' => 'Novo', 'valor_mensal' => 999],
            ],
        ]);

        $response->assertOk()->assertJsonCount(1, 'centro_custo.itens');
        $this->assertDatabaseMissing('centro_custo_itens', ['descricao' => 'Antigo']);
        $this->assertDatabaseHas('centro_custo_itens', ['descricao' => 'Novo', 'centro_custo_id' => $centroCusto->id]);
    }

    public function test_desativar_e_soft_delete(): void
    {
        $empresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        $centroCusto = CentroCusto::factory()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($admin);

        $this->deleteJson("/api/centros-custo/{$centroCusto->uuid}")->assertNoContent();
        $this->assertDatabaseHas('centros_custo', ['id' => $centroCusto->id, 'ativo' => false]);
    }

    public function test_resumo_calcula_custo_por_hora_com_um_promotor_vinculado(): void
    {
        $empresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        // 40h/semana * 52/12 = 173,33h/mês. Individual 2000 + geral 300 (1 promotor) = 2300.
        // 2300 / 173,33 = 13,27
        $centroCusto = CentroCusto::factory()->create(['empresa_id' => $empresa->id, 'carga_horaria_semanal' => 40]);
        \App\Models\CentroCustoItem::create([
            'centro_custo_id' => $centroCusto->id, 'categoria' => 'INDIVIDUAL',
            'descricao' => 'Salário', 'valor_mensal' => 2000, 'ordem' => 0,
        ]);
        \App\Models\CentroCustoItem::create([
            'centro_custo_id' => $centroCusto->id, 'categoria' => 'GERAL',
            'descricao' => 'Sistema', 'valor_mensal' => 300, 'ordem' => 1,
        ]);
        Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id, 'centro_custo_id' => $centroCusto->id]);
        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/centros-custo')->assertOk();

        $resumo = collect($response->json('centros_custo'))->firstWhere('id', $centroCusto->uuid)['resumo'];
        $this->assertSame(1, $resumo['qtd_promotores_ativos']);
        $this->assertEquals(2000.0, $resumo['custo_individual_mensal']);
        $this->assertEquals(300.0, $resumo['custo_geral_mensal']);
        $this->assertEquals(300.0, $resumo['custo_geral_por_promotor']);
        $this->assertEquals(2300.0, $resumo['custo_total_mensal_promotor']);
        $this->assertEqualsWithDelta(173.33, $resumo['horas_mensais'], 0.01);
        $this->assertEqualsWithDelta(13.27, $resumo['custo_por_hora'], 0.01);
    }

    public function test_resumo_divide_custo_geral_pela_quantidade_de_promotores_ativos(): void
    {
        $empresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        $centroCusto = CentroCusto::factory()->create(['empresa_id' => $empresa->id]);
        \App\Models\CentroCustoItem::create([
            'centro_custo_id' => $centroCusto->id, 'categoria' => 'GERAL',
            'descricao' => 'Supervisor', 'valor_mensal' => 1000, 'ordem' => 0,
        ]);
        Usuario::factory()->promotor()->count(4)->create(['empresa_id' => $empresa->id, 'centro_custo_id' => $centroCusto->id]);
        // Promotor inativo não conta.
        Usuario::factory()->promotor()->inativo()->create(['empresa_id' => $empresa->id, 'centro_custo_id' => $centroCusto->id]);
        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/centros-custo')->assertOk();
        $resumo = collect($response->json('centros_custo'))->firstWhere('id', $centroCusto->uuid)['resumo'];

        $this->assertSame(4, $resumo['qtd_promotores_ativos']);
        $this->assertEquals(250.0, $resumo['custo_geral_por_promotor']);
    }

    public function test_resumo_nao_divide_quando_zero_promotores_vinculados(): void
    {
        $empresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        $centroCusto = CentroCusto::factory()->create(['empresa_id' => $empresa->id]);
        \App\Models\CentroCustoItem::create([
            'centro_custo_id' => $centroCusto->id, 'categoria' => 'GERAL',
            'descricao' => 'Sistema', 'valor_mensal' => 500, 'ordem' => 0,
        ]);
        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/centros-custo')->assertOk();
        $resumo = collect($response->json('centros_custo'))->firstWhere('id', $centroCusto->uuid)['resumo'];

        $this->assertSame(0, $resumo['qtd_promotores_ativos']);
        $this->assertEquals(500.0, $resumo['custo_geral_por_promotor']);
    }
}

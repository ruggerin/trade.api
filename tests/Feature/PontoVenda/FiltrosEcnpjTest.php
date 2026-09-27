<?php

namespace Tests\Feature\PontoVenda;

use App\Models\Empresa;
use App\Models\PontoVenda;
use App\Models\RedeLoja;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * docs/02-API-BACKEND.md#pontos-de-venda — campo `cnpj` (novo, opcional, único por empresa) e
 * os filtros refinados de `GET /pontos-venda` (`razao_social`, `fantasia`, `cnpj`, além do
 * `busca` genérico já existente) usados pelo modal de busca avançada do admin web.
 */
class FiltrosEcnpjTest extends TestCase
{
    use RefreshDatabase;

    public function test_cria_ponto_venda_com_cnpj(): void
    {
        $empresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/pontos-venda', [
            'razao_social' => 'Comercial Teste LTDA',
            'fantasia' => 'Comercial Teste',
            'cnpj' => '12.345.678/0001-99',
            'latitude' => -3.1019,
            'longitude' => -60.0250,
            'endereco' => 'Rua Teste, 123',
            'cidade' => 'Manaus',
        ]);

        $response->assertCreated()->assertJsonPath('ponto_venda.cnpj', '12.345.678/0001-99');
    }

    public function test_cnpj_duplicado_na_mesma_empresa_retorna_422(): void
    {
        $empresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        PontoVenda::factory()->create(['empresa_id' => $empresa->id, 'cnpj' => '11.111.111/0001-11']);
        Sanctum::actingAs($admin);

        $this->postJson('/api/pontos-venda', [
            'razao_social' => 'Outra Loja LTDA',
            'fantasia' => 'Outra Loja',
            'cnpj' => '11.111.111/0001-11',
            'latitude' => -3.1019,
            'longitude' => -60.0250,
            'endereco' => 'Rua Outra, 1',
            'cidade' => 'Manaus',
        ])->assertStatus(422)->assertJsonValidationErrors('cnpj');
    }

    public function test_mesmo_cnpj_permitido_em_empresas_diferentes(): void
    {
        $empresaA = Empresa::factory()->create();
        $empresaB = Empresa::factory()->create();
        PontoVenda::factory()->create(['empresa_id' => $empresaA->id, 'cnpj' => '22.222.222/0001-22']);
        $adminB = Usuario::factory()->admin()->create(['empresa_id' => $empresaB->id]);
        Sanctum::actingAs($adminB);

        $this->postJson('/api/pontos-venda', [
            'razao_social' => 'Loja B LTDA',
            'fantasia' => 'Loja B',
            'cnpj' => '22.222.222/0001-22',
            'latitude' => -3.1019,
            'longitude' => -60.0250,
            'endereco' => 'Rua B, 1',
            'cidade' => 'Manaus',
        ])->assertCreated();
    }

    public function test_multiplos_pontos_de_venda_sem_cnpj_nao_conflitam(): void
    {
        $empresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        PontoVenda::factory()->create(['empresa_id' => $empresa->id, 'cnpj' => null]);
        Sanctum::actingAs($admin);

        $this->postJson('/api/pontos-venda', [
            'razao_social' => 'Sem CNPJ LTDA',
            'fantasia' => 'Sem CNPJ',
            'latitude' => -3.1019,
            'longitude' => -60.0250,
            'endereco' => 'Rua Sem CNPJ, 1',
            'cidade' => 'Manaus',
        ])->assertCreated();
    }

    public function test_filtros_refinados_de_razao_social_fantasia_e_cnpj(): void
    {
        $empresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        PontoVenda::factory()->create([
            'empresa_id' => $empresa->id,
            'razao_social' => 'Supermercado Yroiak LTDA',
            'fantasia' => 'Supermercado Yroiak',
            'cnpj' => '33.333.333/0001-33',
        ]);
        PontoVenda::factory()->create([
            'empresa_id' => $empresa->id,
            'razao_social' => 'Comercial Teste LTDA',
            'fantasia' => 'Comercial Teste',
            'cnpj' => '44.444.444/0001-44',
        ]);
        Sanctum::actingAs($admin);

        $this->getJson('/api/pontos-venda?razao_social=Yroiak')
            ->assertOk()
            ->assertJsonCount(1, 'pontos_venda')
            ->assertJsonPath('pontos_venda.0.fantasia', 'Supermercado Yroiak');

        $this->getJson('/api/pontos-venda?fantasia=Comercial')
            ->assertOk()
            ->assertJsonCount(1, 'pontos_venda')
            ->assertJsonPath('pontos_venda.0.fantasia', 'Comercial Teste');

        $this->getJson('/api/pontos-venda?cnpj=33.333.333')
            ->assertOk()
            ->assertJsonCount(1, 'pontos_venda')
            ->assertJsonPath('pontos_venda.0.cnpj', '33.333.333/0001-33');
    }
    // ---- filtros da lista (código externo, rede, cidade, período, sem promotor, recentes) ----

    public function test_lista_filtra_por_codigo_rede_cidade_periodo_e_ordena_por_recentes(): void
    {
        $empresa = Empresa::factory()->create();
        Sanctum::actingAs(Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]));
        $rede = RedeLoja::factory()->create(['empresa_id' => $empresa->id]);

        $antiga = PontoVenda::factory()->create([
            'empresa_id' => $empresa->id, 'fantasia' => 'Antiga', 'codigo_externo' => 'CLI-777',
            'cidade' => 'Manaus', 'created_at' => now()->subMonths(2), 'updated_at' => now()->subMonths(2),
        ]);
        $nova = PontoVenda::factory()->create([
            'empresa_id' => $empresa->id, 'fantasia' => 'Nova', 'rede_loja_id' => $rede->id,
            'cidade' => 'Belém', 'created_at' => now()->subDay(), 'updated_at' => now()->subDay(),
        ]);

        $this->getJson('/api/pontos-venda?busca=cli-777')->assertOk()
            ->assertJsonCount(1, 'pontos_venda')->assertJsonPath('pontos_venda.0.id', $antiga->uuid);
        $this->getJson("/api/pontos-venda?rede_loja_uuid={$rede->uuid}")->assertOk()
            ->assertJsonCount(1, 'pontos_venda')->assertJsonPath('pontos_venda.0.id', $nova->uuid);
        $this->getJson('/api/pontos-venda?cidade=bel')->assertOk()
            ->assertJsonCount(1, 'pontos_venda')->assertJsonPath('pontos_venda.0.id', $nova->uuid);

        $desde = now()->subDays(7)->toDateString();
        $this->getJson("/api/pontos-venda?data_campo=created_at&data_inicio={$desde}")->assertOk()
            ->assertJsonCount(1, 'pontos_venda')->assertJsonPath('pontos_venda.0.id', $nova->uuid);

        // Mexer no cadastro da antiga a leva pra "atualizada recentemente", mas não "cadastrada".
        $antiga->update(['telefone' => '92999990000']);
        $this->getJson("/api/pontos-venda?data_campo=updated_at&data_inicio={$desde}")->assertOk()
            ->assertJsonCount(2, 'pontos_venda');
        $this->getJson('/api/pontos-venda?ordenar=recentes&data_campo=updated_at')->assertOk()
            ->assertJsonPath('pontos_venda.0.id', $antiga->uuid);
        $this->getJson('/api/pontos-venda?ordenar=recentes&data_campo=created_at')->assertOk()
            ->assertJsonPath('pontos_venda.0.id', $nova->uuid);

        $this->getJson('/api/pontos-venda?sem_promotor=1')->assertOk()->assertJsonCount(2, 'pontos_venda');
    }
}

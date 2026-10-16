<?php

namespace Tests\Feature;

use App\Models\Empresa;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Versão da API pro app e o admin mostrarem (achar problema de integração no piloto). */
class VersaoApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_rota_publica_devolve_versao_e_ambiente(): void
    {
        config(['app.versao' => '2026.10.16-abc1234']);

        $this->getJson('/api/versao')->assertOk()
            ->assertExactJson(['versao' => '2026.10.16-abc1234', 'ambiente' => config('app.env')])
            ->assertHeader('X-Api-Version', '2026.10.16-abc1234');
    }

    public function test_toda_resposta_da_api_leva_a_versao_inclusive_erro(): void
    {
        config(['app.versao' => '2026.10.16-abc1234']);
        Sanctum::actingAs(Usuario::factory()->admin()->create(['empresa_id' => Empresa::factory()->create()->id]));

        $this->getJson('/api/auth/me')->assertOk()->assertHeader('X-Api-Version', '2026.10.16-abc1234');
        $this->getJson('/api/pontos-venda/'.fake()->uuid())->assertNotFound()->assertHeader('X-Api-Version', '2026.10.16-abc1234');
    }

    public function test_versao_minima_recusada_tambem_informa_a_versao_da_api(): void
    {
        config(['app.versao' => '2026.10.16-abc1234', 'seguranca.versao_minima_app' => '1.1.0']);

        $this->postJson('/api/auth/login', [], ['X-Client' => 'mobile', 'X-App-Version' => '1.0.0'])
            ->assertStatus(426)->assertHeader('X-Api-Version', '2026.10.16-abc1234');
    }

    public function test_sem_carimbo_a_api_se_apresenta_como_dev(): void
    {
        $this->assertSame('dev', config('app.versao'));
    }
}

<?php

namespace Tests\Feature;

use App\Models\Empresa;
use App\Models\Parametro;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ParametroTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_cria_parametro(): void
    {
        $empresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/parametros', [
            'chave' => 'CHECKIN_RAIO_METROS',
            'valor' => '300',
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('parametros', [
            'chave' => 'CHECKIN_RAIO_METROS',
            'valor' => '300',
            'empresa_id' => $empresa->id,
        ]);
    }

    public function test_rejeita_chave_em_formato_invalido(): void
    {
        $empresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($admin);

        $this->postJson('/api/parametros', [
            'chave' => 'chave em minusculo',
            'valor' => '1',
        ])->assertStatus(422)->assertJsonValidationErrors('chave');
    }

    public function test_chave_e_unica_por_empresa_mas_nao_globalmente(): void
    {
        $empresaA = Empresa::factory()->create();
        $empresaB = Empresa::factory()->create();
        Parametro::create(['empresa_id' => $empresaA->id, 'chave' => 'CHECKIN_RAIO_METROS', 'valor' => '200']);

        $adminA = Usuario::factory()->admin()->create(['empresa_id' => $empresaA->id]);
        $adminB = Usuario::factory()->admin()->create(['empresa_id' => $empresaB->id]);

        Sanctum::actingAs($adminA);
        $this->postJson('/api/parametros', [
            'chave' => 'CHECKIN_RAIO_METROS',
            'valor' => '500',
        ])->assertStatus(422)->assertJsonValidationErrors('chave');

        Sanctum::actingAs($adminB);
        $this->postJson('/api/parametros', [
            'chave' => 'CHECKIN_RAIO_METROS',
            'valor' => '500',
        ])->assertCreated();
    }

    public function test_promotor_nao_gerencia_parametros_mas_le(): void
    {
        $empresa = Empresa::factory()->create();
        Parametro::create(['empresa_id' => $empresa->id, 'chave' => 'CHECKIN_RAIO_METROS', 'valor' => '200']);
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($promotor);

        $this->getJson('/api/parametros')->assertOk();
        $this->postJson('/api/parametros', ['chave' => 'OUTRA_CHAVE', 'valor' => '1'])->assertForbidden();
    }
}

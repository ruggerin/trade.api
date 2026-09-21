<?php

namespace Tests\Feature;

use App\Enums\Permissao;
use App\Models\Empresa;
use App\Models\Parametro;
use App\Models\Perfil;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * docs/11-RASTREAMENTO-TEMPO-REAL.md — posição mais recente do promotor (sem histórico) e o mapa
 * ao vivo do admin.
 */
class RastreamentoTest extends TestCase
{
    use RefreshDatabase;

    private function habilitar(Empresa $empresa, string $valor = '60'): void
    {
        Parametro::create([
            'empresa_id' => $empresa->id,
            'chave' => 'RASTREAMENTO_INTERVALO_SEGUNDOS',
            'valor' => $valor,
            'ativo' => true,
        ]);
    }

    public function test_promotor_envia_a_propria_posicao(): void
    {
        $empresa = Empresa::factory()->create();
        $this->habilitar($empresa);
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($promotor);

        $this->patchJson('/api/localizacao', ['latitude' => -3.1, 'longitude' => -60.0])->assertNoContent();

        $promotor->refresh();
        $this->assertEqualsWithDelta(-3.1, $promotor->ultima_localizacao_latitude, 0.0001);
        $this->assertEqualsWithDelta(-60.0, $promotor->ultima_localizacao_longitude, 0.0001);
        $this->assertNotNull($promotor->ultima_localizacao_em);
    }

    public function test_bloqueia_quando_empresa_nao_habilitou_o_rastreamento(): void
    {
        $empresa = Empresa::factory()->create();
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($promotor);

        $this->patchJson('/api/localizacao', ['latitude' => -3.1, 'longitude' => -60.0])->assertForbidden();
        $this->assertNull($promotor->fresh()->ultima_localizacao_em);
    }

    public function test_parametro_zero_conta_como_desligado(): void
    {
        $empresa = Empresa::factory()->create();
        $this->habilitar($empresa, '0');
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($promotor);

        $this->patchJson('/api/localizacao', ['latitude' => -3.1, 'longitude' => -60.0])->assertForbidden();
    }

    public function test_so_promotor_envia_posicao(): void
    {
        $empresa = Empresa::factory()->create();
        $this->habilitar($empresa);
        Sanctum::actingAs(Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]));

        $this->patchJson('/api/localizacao', ['latitude' => -3.1, 'longitude' => -60.0])->assertForbidden();
    }

    public function test_valida_coordenadas(): void
    {
        $empresa = Empresa::factory()->create();
        $this->habilitar($empresa);
        Sanctum::actingAs(Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]));

        $this->patchJson('/api/localizacao', ['latitude' => 120, 'longitude' => -60.0])
            ->assertStatus(422)->assertJsonValidationErrors('latitude');
        $this->patchJson('/api/localizacao', ['latitude' => -3.1])
            ->assertStatus(422)->assertJsonValidationErrors('longitude');
    }

    public function test_envio_atrasado_nao_sobrescreve_posicao_mais_recente(): void
    {
        $empresa = Empresa::factory()->create();
        $this->habilitar($empresa);
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($promotor);

        $this->patchJson('/api/localizacao', ['latitude' => -3.1, 'longitude' => -60.0, 'capturado_em' => now()->subMinute()->toIso8601String()])->assertNoContent();
        $this->patchJson('/api/localizacao', ['latitude' => -9.9, 'longitude' => -70.0, 'capturado_em' => now()->subMinutes(10)->toIso8601String()])->assertNoContent();

        $this->assertEqualsWithDelta(-3.1, $promotor->fresh()->ultima_localizacao_latitude, 0.0001);
    }

    public function test_horario_do_futuro_e_limitado_ao_agora(): void
    {
        $empresa = Empresa::factory()->create();
        $this->habilitar($empresa);
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($promotor);

        $this->patchJson('/api/localizacao', ['latitude' => -3.1, 'longitude' => -60.0, 'capturado_em' => now()->addHours(3)->toIso8601String()])->assertNoContent();

        $this->assertFalse($promotor->fresh()->ultima_localizacao_em->isFuture());
    }

    public function test_admin_lista_localizacoes_com_ativo_agora_calculado(): void
    {
        $empresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        $recente = Usuario::factory()->promotor()->create([
            'empresa_id' => $empresa->id, 'nome' => 'Recente',
            'ultima_localizacao_latitude' => -3.1, 'ultima_localizacao_longitude' => -60.0,
            'ultima_localizacao_em' => now()->subMinute(),
        ]);
        $antigo = Usuario::factory()->promotor()->create([
            'empresa_id' => $empresa->id, 'nome' => 'Antigo',
            'ultima_localizacao_latitude' => -3.2, 'ultima_localizacao_longitude' => -60.1,
            'ultima_localizacao_em' => now()->subMinutes(30),
        ]);
        Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id, 'nome' => 'Nunca compartilhou']);
        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/localizacoes')->assertOk();

        $this->assertCount(2, $response->json('localizacoes'));
        $porId = collect($response->json('localizacoes'))->keyBy('id');
        $this->assertTrue($porId[$recente->uuid]['ativo_agora']);
        $this->assertFalse($porId[$antigo->uuid]['ativo_agora']);
    }

    public function test_lista_isolada_por_empresa(): void
    {
        $empresaA = Empresa::factory()->create();
        $empresaB = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresaA->id]);
        Usuario::factory()->promotor()->create([
            'empresa_id' => $empresaB->id,
            'ultima_localizacao_latitude' => -3.1, 'ultima_localizacao_longitude' => -60.0,
            'ultima_localizacao_em' => now(),
        ]);
        Sanctum::actingAs($admin);

        $this->assertCount(0, $this->getJson('/api/localizacoes')->assertOk()->json('localizacoes'));
    }

    public function test_gestor_sem_permissao_e_bloqueado_e_com_permissao_passa(): void
    {
        $empresa = Empresa::factory()->create();
        $gestor = Usuario::factory()->gestor()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($gestor);
        $this->getJson('/api/localizacoes')->assertForbidden();

        $perfil = Perfil::factory()->comPermissoes([Permissao::RASTREAMENTO_VISUALIZAR->value])
            ->create(['empresa_id' => $empresa->id]);
        $gestor->update(['perfil_id' => $perfil->id]);
        Sanctum::actingAs($gestor->fresh());
        $this->getJson('/api/localizacoes')->assertOk();
    }

    public function test_promotor_nao_le_o_mapa(): void
    {
        $empresa = Empresa::factory()->create();
        Sanctum::actingAs(Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]));

        $this->getJson('/api/localizacoes')->assertForbidden();
    }
}

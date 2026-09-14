<?php

namespace Tests\Feature\Visita;

use App\Models\Empresa;
use App\Models\PontoVenda;
use App\Models\TipoRegistro;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Marcar um alerta (VisitaRegistro de um TipoRegistro com eh_alerta=true) como resolvido —
 * Painel de Atividades. Ver VisitaRegistroController::resolverAlerta e
 * docs/17-PAINEL-ATIVIDADES.md.
 */
class ResolverAlertaTest extends TestCase
{
    use RefreshDatabase;

    private function abrirVisitaComAlerta(Empresa $empresa, PontoVenda $pdv, Usuario $promotor): array
    {
        $tipoAlerta = TipoRegistro::create(['empresa_id' => $empresa->id, 'descricao' => 'Avaria', 'eh_alerta' => true]);

        Sanctum::actingAs($promotor);
        $visitaUuid = $this->postJson('/api/visitas', [
            'ponto_venda_uuid' => $pdv->uuid, 'latitude' => $pdv->latitude, 'longitude' => $pdv->longitude,
        ])->json('visita.id');
        $registroUuid = $this->postJson("/api/visitas/{$visitaUuid}/registros", [
            'tipo_registro_uuid' => $tipoAlerta->uuid, 'observacao' => 'Caixa amassada',
        ])->json('registro.id');

        return [$visitaUuid, $registroUuid];
    }

    public function test_admin_resolve_o_alerta(): void
    {
        $empresa = Empresa::factory()->create();
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        [$visitaUuid, $registroUuid] = $this->abrirVisitaComAlerta($empresa, $pdv, $promotor);

        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($admin);

        $response = $this->postJson("/api/visitas/{$visitaUuid}/registros/{$registroUuid}/resolver-alerta");

        $response->assertOk();
        $this->assertNotNull($response->json('registro.alerta_resolvido_em'));
        $this->assertSame($admin->uuid, $response->json('registro.resolvido_por.id'));
    }

    public function test_gestor_tambem_resolve(): void
    {
        $empresa = Empresa::factory()->create();
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        [$visitaUuid, $registroUuid] = $this->abrirVisitaComAlerta($empresa, $pdv, $promotor);

        $gestor = Usuario::factory()->gestor()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($gestor);

        $this->postJson("/api/visitas/{$visitaUuid}/registros/{$registroUuid}/resolver-alerta")->assertOk();
    }

    public function test_promotor_nao_pode_resolver(): void
    {
        $empresa = Empresa::factory()->create();
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        [$visitaUuid, $registroUuid] = $this->abrirVisitaComAlerta($empresa, $pdv, $promotor);

        Sanctum::actingAs($promotor);
        $this->postJson("/api/visitas/{$visitaUuid}/registros/{$registroUuid}/resolver-alerta")->assertForbidden();
    }

    public function test_resolver_duas_vezes_e_idempotente(): void
    {
        $empresa = Empresa::factory()->create();
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        [$visitaUuid, $registroUuid] = $this->abrirVisitaComAlerta($empresa, $pdv, $promotor);

        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($admin);

        $primeira = $this->postJson("/api/visitas/{$visitaUuid}/registros/{$registroUuid}/resolver-alerta")
            ->assertOk()->json('registro.alerta_resolvido_em');
        $segunda = $this->postJson("/api/visitas/{$visitaUuid}/registros/{$registroUuid}/resolver-alerta")
            ->assertOk()->json('registro.alerta_resolvido_em');

        $this->assertSame($primeira, $segunda);
    }
}

<?php

namespace Tests\Feature\Visita;

use App\Models\Empresa;
use App\Models\MotivoResolucaoAlerta;
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

    public function test_admin_resolve_o_alerta_com_motivo_texto(): void
    {
        $empresa = Empresa::factory()->create();
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        [$visitaUuid, $registroUuid] = $this->abrirVisitaComAlerta($empresa, $pdv, $promotor);

        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($admin);

        $response = $this->postJson("/api/visitas/{$visitaUuid}/registros/{$registroUuid}/resolver-alerta", [
            'motivo_texto' => 'Já reposto na loja',
        ]);

        $response->assertOk();
        $this->assertNotNull($response->json('registro.alerta_resolvido_em'));
        $this->assertSame($admin->uuid, $response->json('registro.resolvido_por.id'));
        $this->assertSame('Já reposto na loja', $response->json('registro.alerta_motivo_texto'));
        $this->assertNull($response->json('registro.alerta_motivo'));
    }

    public function test_admin_resolve_o_alerta_com_motivo_do_catalogo(): void
    {
        $empresa = Empresa::factory()->create();
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        [$visitaUuid, $registroUuid] = $this->abrirVisitaComAlerta($empresa, $pdv, $promotor);
        $motivo = MotivoResolucaoAlerta::create(['empresa_id' => $empresa->id, 'descricao' => 'Ruptura da indústria']);

        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($admin);

        $response = $this->postJson("/api/visitas/{$visitaUuid}/registros/{$registroUuid}/resolver-alerta", [
            'motivo_uuid' => $motivo->uuid,
        ]);

        $response->assertOk();
        $this->assertSame($motivo->uuid, $response->json('registro.alerta_motivo.id'));
        $this->assertSame('Ruptura da indústria', $response->json('registro.alerta_motivo.descricao'));
    }

    public function test_resolver_sem_motivo_nenhum_e_rejeitado(): void
    {
        $empresa = Empresa::factory()->create();
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        [$visitaUuid, $registroUuid] = $this->abrirVisitaComAlerta($empresa, $pdv, $promotor);

        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($admin);

        $this->postJson("/api/visitas/{$visitaUuid}/registros/{$registroUuid}/resolver-alerta")
            ->assertInvalid(['motivo_uuid', 'motivo_texto']);
    }

    public function test_motivo_uuid_de_outra_empresa_e_rejeitado(): void
    {
        $empresa = Empresa::factory()->create();
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        [$visitaUuid, $registroUuid] = $this->abrirVisitaComAlerta($empresa, $pdv, $promotor);

        $outraEmpresa = Empresa::factory()->create();
        $motivoDeOutraEmpresa = MotivoResolucaoAlerta::create(['empresa_id' => $outraEmpresa->id, 'descricao' => 'Outro']);

        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($admin);

        $this->postJson("/api/visitas/{$visitaUuid}/registros/{$registroUuid}/resolver-alerta", [
            'motivo_uuid' => $motivoDeOutraEmpresa->uuid,
        ])->assertInvalid(['motivo_uuid']);
    }

    public function test_gestor_tambem_resolve(): void
    {
        $empresa = Empresa::factory()->create();
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        [$visitaUuid, $registroUuid] = $this->abrirVisitaComAlerta($empresa, $pdv, $promotor);

        $gestor = Usuario::factory()->gestor()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($gestor);

        $this->postJson("/api/visitas/{$visitaUuid}/registros/{$registroUuid}/resolver-alerta", [
            'motivo_texto' => 'Resolvido em campo',
        ])->assertOk();
    }

    public function test_promotor_nao_pode_resolver(): void
    {
        $empresa = Empresa::factory()->create();
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        [$visitaUuid, $registroUuid] = $this->abrirVisitaComAlerta($empresa, $pdv, $promotor);

        Sanctum::actingAs($promotor);
        $this->postJson("/api/visitas/{$visitaUuid}/registros/{$registroUuid}/resolver-alerta", [
            'motivo_texto' => 'Resolvido em campo',
        ])->assertForbidden();
    }

    public function test_resolver_duas_vezes_e_idempotente_mantem_motivo_original(): void
    {
        $empresa = Empresa::factory()->create();
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        [$visitaUuid, $registroUuid] = $this->abrirVisitaComAlerta($empresa, $pdv, $promotor);

        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($admin);

        $primeira = $this->postJson("/api/visitas/{$visitaUuid}/registros/{$registroUuid}/resolver-alerta", [
            'motivo_texto' => 'Primeiro motivo',
        ])->assertOk();
        $segunda = $this->postJson("/api/visitas/{$visitaUuid}/registros/{$registroUuid}/resolver-alerta", [
            'motivo_texto' => 'Segundo motivo, nunca deveria aparecer',
        ])->assertOk();

        $this->assertSame($primeira->json('registro.alerta_resolvido_em'), $segunda->json('registro.alerta_resolvido_em'));
        $this->assertSame('Primeiro motivo', $segunda->json('registro.alerta_motivo_texto'));
    }
}

<?php

namespace Tests\Feature;

use App\Models\Contrato;
use App\Models\Empresa;
use App\Models\PontoVenda;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * docs/02-API-BACKEND.md#contratos — GET /api/contratos/{uuid} (show) e
 * GET /api/contratos/{uuid}/historico, e o registro automático de eventos em
 * ContratoController::registrarHistorico.
 */
class ContratoHistoricoTest extends TestCase
{
    use RefreshDatabase;

    public function test_criar_contrato_registra_evento_de_criacao(): void
    {
        $empresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($admin);

        $contratoUuid = $this->postJson('/api/contratos', [
            'ponto_venda_uuid' => $pdv->uuid,
            'tipo' => 'COMODATO',
            'vigencia_inicio' => '2026-01-01',
            'vigencia_fim' => '2026-12-31',
        ])->json('contrato.id');

        $historico = $this->getJson("/api/contratos/{$contratoUuid}/historico")->assertOk();

        $this->assertCount(1, $historico->json('historico'));
        $this->assertEquals('Contrato criado', $historico->json('historico.0.descricao'));
        $this->assertEquals($admin->uuid, $historico->json('historico.0.usuario.id'));
    }

    public function test_editar_contrato_registra_o_que_mudou(): void
    {
        $empresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        $contrato = Contrato::create([
            'empresa_id' => $empresa->id,
            'ponto_venda_id' => $pdv->id,
            'tipo' => 'COMODATO',
            'descricao' => 'Freezer 2 portas',
            'vigencia_inicio' => '2026-01-01',
            'vigencia_fim' => '2026-12-31',
        ]);
        Sanctum::actingAs($admin);

        $this->putJson("/api/contratos/{$contrato->uuid}", [
            'tipo' => 'PONTO_EXTRA',
            'vigencia_fim' => '2027-06-30',
        ])->assertOk();

        $historico = $this->getJson("/api/contratos/{$contrato->uuid}/historico")->assertOk();
        $descricoes = collect($historico->json('historico'))->pluck('descricao');

        $this->assertTrue($descricoes->contains('Tipo alterado de COMODATO para PONTO_EXTRA'));
        $this->assertTrue($descricoes->contains(fn ($d) => str_starts_with($d, 'Vigência alterada para')));
        // Descrição não mudou — não deve gerar evento.
        $this->assertFalse($descricoes->contains('Descrição alterada'));
    }

    public function test_editar_sem_mudar_nada_nao_gera_evento_extra(): void
    {
        $empresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        $contrato = Contrato::create([
            'empresa_id' => $empresa->id,
            'ponto_venda_id' => $pdv->id,
            'tipo' => 'COMODATO',
            'vigencia_inicio' => '2026-01-01',
            'vigencia_fim' => '2026-12-31',
        ]);
        Sanctum::actingAs($admin);

        // Manda os mesmos valores que já estavam lá.
        $this->putJson("/api/contratos/{$contrato->uuid}", [
            'tipo' => 'COMODATO',
        ])->assertOk();

        $this->assertDatabaseCount('contrato_historicos', 0);
    }

    public function test_desativar_e_reativar_registram_eventos(): void
    {
        $empresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        $contrato = Contrato::create([
            'empresa_id' => $empresa->id,
            'ponto_venda_id' => $pdv->id,
            'tipo' => 'COMODATO',
            'vigencia_inicio' => '2026-01-01',
            'vigencia_fim' => '2026-12-31',
        ]);
        Sanctum::actingAs($admin);

        $this->deleteJson("/api/contratos/{$contrato->uuid}")->assertNoContent();
        $this->putJson("/api/contratos/{$contrato->uuid}", ['ativo' => true])->assertOk();

        $descricoes = collect($this->getJson("/api/contratos/{$contrato->uuid}/historico")->json('historico'))->pluck('descricao');
        $this->assertTrue($descricoes->contains('Contrato desativado'));
        $this->assertTrue($descricoes->contains('Contrato reativado'));
    }

    public function test_show_traz_contrato_com_ponto_venda_e_metas(): void
    {
        $empresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        $contrato = Contrato::create([
            'empresa_id' => $empresa->id,
            'ponto_venda_id' => $pdv->id,
            'tipo' => 'COMODATO',
            'vigencia_inicio' => '2026-01-01',
            'vigencia_fim' => '2026-12-31',
        ]);
        Sanctum::actingAs($admin);

        $response = $this->getJson("/api/contratos/{$contrato->uuid}")->assertOk();

        $this->assertEquals($pdv->uuid, $response->json('contrato.ponto_venda.id'));
        $this->assertEquals([], $response->json('contrato.metas'));
    }

    public function test_promotor_nunca_acessa_show_nem_historico(): void
    {
        $empresa = Empresa::factory()->create();
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        $contrato = Contrato::create([
            'empresa_id' => $empresa->id,
            'ponto_venda_id' => $pdv->id,
            'tipo' => 'COMODATO',
            'vigencia_inicio' => '2026-01-01',
            'vigencia_fim' => '2026-12-31',
        ]);
        Sanctum::actingAs($promotor);

        $this->getJson("/api/contratos/{$contrato->uuid}")->assertForbidden();
        $this->getJson("/api/contratos/{$contrato->uuid}/historico")->assertForbidden();
    }
}

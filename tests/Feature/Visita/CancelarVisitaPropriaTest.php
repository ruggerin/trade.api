<?php

namespace Tests\Feature\Visita;

use App\Enums\StatusOrdemServico;
use App\Models\Empresa;
use App\Models\OrdemServico;
use App\Models\Parametro;
use App\Models\PontoVenda;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Autosserviço: promotor cancela a própria visita em andamento, parametrizável por empresa
 * (VISITA_CANCELAMENTO_PERMITIDO, default desligado). Diferente da intervenção administrativa
 * (visitas.intervir), que é exclusiva de ADMIN/GESTOR — ver
 * tests/Feature/Visita/IntervencaoAdministrativaTest.php (se existir) e
 * App\Support\CancelamentoVisita.
 */
class CancelarVisitaPropriaTest extends TestCase
{
    use RefreshDatabase;

    private function abrirVisita(Usuario $promotor, PontoVenda $pdv): string
    {
        Sanctum::actingAs($promotor);

        return $this->postJson('/api/visitas', [
            'ponto_venda_uuid' => $pdv->uuid,
            'latitude' => $pdv->latitude,
            'longitude' => $pdv->longitude,
        ])->json('visita.id');
    }

    private function ligarParametro(Empresa $empresa): void
    {
        Parametro::create(['empresa_id' => $empresa->id, 'chave' => 'VISITA_CANCELAMENTO_PERMITIDO', 'valor' => 'true']);
    }

    public function test_por_padrao_promotor_nao_pode_cancelar_a_propria_visita(): void
    {
        $empresa = Empresa::factory()->create();
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        $visitaUuid = $this->abrirVisita($promotor, $pdv);

        $this->postJson("/api/visitas/{$visitaUuid}/cancelar-propria")->assertForbidden();
    }

    public function test_promotor_cancela_a_propria_visita_com_parametro_ligado(): void
    {
        $empresa = Empresa::factory()->create();
        $this->ligarParametro($empresa);
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        $visitaUuid = $this->abrirVisita($promotor, $pdv);

        $response = $this->postJson("/api/visitas/{$visitaUuid}/cancelar-propria");

        $response->assertOk();
        $this->assertSame('CANCELADA', $response->json('visita.status'));
        $this->assertDatabaseHas('visitas', ['uuid' => $visitaUuid, 'status' => 'CANCELADA']);
    }

    public function test_promotor_nao_cancela_visita_de_outro_promotor(): void
    {
        $empresa = Empresa::factory()->create();
        $this->ligarParametro($empresa);
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        $dono = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        $outro = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        $visitaUuid = $this->abrirVisita($dono, $pdv);

        Sanctum::actingAs($outro);
        $this->postJson("/api/visitas/{$visitaUuid}/cancelar-propria")->assertForbidden();
    }

    public function test_nao_cancela_visita_ja_finalizada(): void
    {
        $empresa = Empresa::factory()->create();
        $this->ligarParametro($empresa);
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        $visitaUuid = $this->abrirVisita($promotor, $pdv);

        $this->patchJson("/api/visitas/{$visitaUuid}/checkout", [
            'latitude' => $pdv->latitude, 'longitude' => $pdv->longitude,
        ])->assertOk();

        $this->postJson("/api/visitas/{$visitaUuid}/cancelar-propria")->assertStatus(422);
    }

    public function test_admin_nao_usa_esta_rota(): void
    {
        $empresa = Empresa::factory()->create();
        $this->ligarParametro($empresa);
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        $visitaUuid = $this->abrirVisita($promotor, $pdv);

        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($admin);

        $this->postJson("/api/visitas/{$visitaUuid}/cancelar-propria")->assertForbidden();
    }

    public function test_cancelamento_libera_ordem_de_servico_vinculada(): void
    {
        $empresa = Empresa::factory()->create();
        $this->ligarParametro($empresa);
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);

        $os = OrdemServico::factory()->create([
            'empresa_id' => $empresa->id,
            'ponto_venda_id' => $pdv->id,
            'usuario_id' => $promotor->id,
            'status' => StatusOrdemServico::PENDENTE,
        ]);

        Sanctum::actingAs($promotor);
        $visitaUuid = $this->postJson('/api/visitas', [
            'ponto_venda_uuid' => $pdv->uuid,
            'latitude' => $pdv->latitude,
            'longitude' => $pdv->longitude,
            'ordem_servico_uuid' => $os->uuid,
        ])->json('visita.id');

        $this->postJson("/api/visitas/{$visitaUuid}/cancelar-propria")->assertOk();

        $this->assertDatabaseHas('ordens_servico', [
            'id' => $os->id, 'status' => 'PENDENTE', 'visita_id' => null,
        ]);
    }
}

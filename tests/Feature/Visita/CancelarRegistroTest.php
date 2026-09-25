<?php

namespace Tests\Feature\Visita;

use App\Models\Empresa;
use App\Models\Parametro;
use App\Models\PontoVenda;
use App\Models\TipoRegistro;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Cancelamento (soft) de um registro já feito — parametrizável por empresa pra PROMOTOR
 * (REGISTRO_CANCELAMENTO_PERMITIDO, default desligado); ADMIN/GESTOR sempre podem. Ver
 * App\Support\CancelamentoRegistro e VisitaRegistroController::cancelar.
 */
class CancelarRegistroTest extends TestCase
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

    private function criarRegistro(Empresa $empresa, string $visitaUuid): string
    {
        $tipoUuid = TipoRegistro::withoutGlobalScopes()
            ->where('empresa_id', $empresa->id)->where('descricao', 'Observação')->value('uuid');

        return $this->postJson("/api/visitas/{$visitaUuid}/registros", [
            'tipo_registro_uuid' => $tipoUuid, 'observacao' => 'Teste',
        ])->json('registro.id');
    }

    private function ligarParametro(Empresa $empresa): void
    {
        Parametro::create(['empresa_id' => $empresa->id, 'chave' => 'REGISTRO_CANCELAMENTO_PERMITIDO', 'valor' => 'true']);
    }

    public function test_por_padrao_promotor_nao_pode_cancelar(): void
    {
        $empresa = Empresa::factory()->create();
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        $visitaUuid = $this->abrirVisita($promotor, $pdv);
        $registroUuid = $this->criarRegistro($empresa, $visitaUuid);

        $this->postJson("/api/visitas/{$visitaUuid}/registros/{$registroUuid}/cancelar")->assertForbidden();
    }

    public function test_promotor_cancela_o_proprio_registro_com_parametro_ligado(): void
    {
        $empresa = Empresa::factory()->create();
        $this->ligarParametro($empresa);
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        $visitaUuid = $this->abrirVisita($promotor, $pdv);
        $registroUuid = $this->criarRegistro($empresa, $visitaUuid);

        $response = $this->postJson("/api/visitas/{$visitaUuid}/registros/{$registroUuid}/cancelar");

        $response->assertOk();
        $this->assertNotNull($response->json('registro.cancelado_em'));
        $this->assertDatabaseHas('visita_registros', ['uuid' => $registroUuid]);
    }

    public function test_promotor_nao_cancela_registro_de_visita_de_outro_promotor(): void
    {
        $empresa = Empresa::factory()->create();
        $this->ligarParametro($empresa);
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        $dono = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        $outro = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        $visitaUuid = $this->abrirVisita($dono, $pdv);
        $registroUuid = $this->criarRegistro($empresa, $visitaUuid);

        Sanctum::actingAs($outro);
        $this->postJson("/api/visitas/{$visitaUuid}/registros/{$registroUuid}/cancelar")->assertForbidden();
    }

    public function test_admin_cancela_mesmo_sem_parametro_ligado(): void
    {
        $empresa = Empresa::factory()->create();
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        $visitaUuid = $this->abrirVisita($promotor, $pdv);
        $registroUuid = $this->criarRegistro($empresa, $visitaUuid);

        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($admin);

        $this->postJson("/api/visitas/{$visitaUuid}/registros/{$registroUuid}/cancelar")
            ->assertOk()->assertJsonPath('registro.cancelado_em', fn ($v) => $v !== null);
    }

    public function test_cancelar_duas_vezes_retorna_422_na_segunda(): void
    {
        $empresa = Empresa::factory()->create();
        $this->ligarParametro($empresa);
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        $visitaUuid = $this->abrirVisita($promotor, $pdv);
        $registroUuid = $this->criarRegistro($empresa, $visitaUuid);

        $this->postJson("/api/visitas/{$visitaUuid}/registros/{$registroUuid}/cancelar")->assertOk();
        $this->postJson("/api/visitas/{$visitaUuid}/registros/{$registroUuid}/cancelar")->assertStatus(422);
    }

    public function test_registro_de_outra_visita_retorna_404(): void
    {
        $empresa = Empresa::factory()->create();
        $this->ligarParametro($empresa);
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        $visitaUuidA = $this->abrirVisita($promotor, $pdv);
        $registroUuidA = $this->criarRegistro($empresa, $visitaUuidA);
        // Uma visita ABERTA por vez (RetomadaEAutorizacaoTest) — finaliza A antes de abrir B em
        // outra loja (no MESMO PDV o check-in retomaria a visita aberta em vez de criar outra).
        Sanctum::actingAs($promotor);
        $this->patchJson("/api/visitas/{$visitaUuidA}/checkout", ['latitude' => $pdv->latitude, 'longitude' => $pdv->longitude])->assertOk();
        $visitaUuidB = $this->abrirVisita($promotor, PontoVenda::factory()->create(['empresa_id' => $empresa->id]));

        Sanctum::actingAs($promotor);
        $this->postJson("/api/visitas/{$visitaUuidB}/registros/{$registroUuidA}/cancelar")->assertNotFound();
    }

    public function test_contagem_de_registros_da_visita_exclui_cancelados(): void
    {
        $empresa = Empresa::factory()->create();
        $this->ligarParametro($empresa);
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        $visitaUuid = $this->abrirVisita($promotor, $pdv);
        $this->criarRegistro($empresa, $visitaUuid);
        $registroCancelado = $this->criarRegistro($empresa, $visitaUuid);
        $this->postJson("/api/visitas/{$visitaUuid}/registros/{$registroCancelado}/cancelar")->assertOk();

        $response = $this->getJson('/api/visitas')->assertOk();
        $visita = collect($response->json('visitas'))->firstWhere('id', $visitaUuid);
        $this->assertSame(1, $visita['registros_count']);
    }
}

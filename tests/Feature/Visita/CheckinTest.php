<?php

namespace Tests\Feature\Visita;

use App\Models\CampanhaAuditoria;
use App\Models\Empresa;
use App\Models\Parametro;
use App\Models\PontoVenda;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * docs/02-API-BACKEND.md, regra de negócio 1 — a distância é sempre recalculada no servidor
 * (Haversine), nunca confia no valor do cliente. É a correção mais importante em relação ao
 * sistema antigo (que gravava a distância mandada pelo próprio app, sem checar nada).
 */
class CheckinTest extends TestCase
{
    use RefreshDatabase;

    public function test_checkin_dentro_do_raio_cria_visita_aberta(): void
    {
        $empresa = Empresa::factory()->create();
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);

        Sanctum::actingAs($promotor);

        $response = $this->postJson('/api/visitas', [
            'ponto_venda_uuid' => $pdv->uuid,
            // Mesma coordenada do PDV — distância 0, sempre dentro de qualquer raio.
            'latitude' => $pdv->latitude,
            'longitude' => $pdv->longitude,
        ]);

        $response->assertCreated()
            ->assertJsonPath('visita.status', 'ABERTA');

        $this->assertDatabaseHas('visitas', [
            'usuario_id' => $promotor->id,
            'ponto_venda_id' => $pdv->id,
            'status' => 'ABERTA',
        ]);
    }

    public function test_checkin_fora_do_raio_padrao_retorna_422_com_distancia(): void
    {
        $empresa = Empresa::factory()->create();
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);

        Sanctum::actingAs($promotor);

        $response = $this->postJson('/api/visitas', [
            'ponto_venda_uuid' => $pdv->uuid,
            // ~11km de distância (0.1 grau de latitude) — bem fora do raio padrão (200m).
            'latitude' => $pdv->latitude - 0.1,
            'longitude' => $pdv->longitude,
        ]);

        $response->assertStatus(422)->assertJsonStructure(['message', 'distancia_metros']);
        $this->assertGreaterThan(200, $response->json('distancia_metros'));

        $this->assertDatabaseCount('visitas', 0);
    }

    public function test_checkin_usa_raio_customizado_da_empresa_quando_configurado(): void
    {
        $empresa = Empresa::factory()->create();
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);

        Parametro::create([
            'empresa_id' => $empresa->id,
            'chave' => 'CHECKIN_RAIO_METROS',
            'valor' => '5000',
            'ativo' => true,
        ]);

        Sanctum::actingAs($promotor);

        // ~3.3km de distância (0.03 grau) — fora do padrão de 200m, mas dentro do raio
        // customizado de 5000m dessa empresa.
        $response = $this->postJson('/api/visitas', [
            'ponto_venda_uuid' => $pdv->uuid,
            'latitude' => $pdv->latitude - 0.03,
            'longitude' => $pdv->longitude,
        ]);

        $response->assertCreated();
    }

    public function test_checkin_com_raio_desativado_nao_tem_limite_de_distancia(): void
    {
        $empresa = Empresa::factory()->create();
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);

        // Diferente do resto do catálogo de parâmetros: desativar CHECKIN_RAIO_METROS não volta
        // pro default do sistema, desliga a validação de raio por completo — ver RaioCheckin.
        Parametro::create([
            'empresa_id' => $empresa->id,
            'chave' => 'CHECKIN_RAIO_METROS',
            'valor' => '250',
            'ativo' => false,
        ]);

        Sanctum::actingAs($promotor);

        // ~11km de distância (0.1 grau) — bem além de qualquer raio configurável razoável.
        $response = $this->postJson('/api/visitas', [
            'ponto_venda_uuid' => $pdv->uuid,
            'latitude' => $pdv->latitude - 0.1,
            'longitude' => $pdv->longitude,
        ]);

        $response->assertCreated();
    }

    public function test_promotor_nao_acessa_visita_de_outro_usuario(): void
    {
        $empresa = Empresa::factory()->create();
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        $dono = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        $outroPromotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);

        Sanctum::actingAs($dono);
        $visitaUuid = $this->postJson('/api/visitas', [
            'ponto_venda_uuid' => $pdv->uuid,
            'latitude' => $pdv->latitude,
            'longitude' => $pdv->longitude,
        ])->json('visita.id');

        Sanctum::actingAs($outroPromotor);
        $this->getJson("/api/visitas/{$visitaUuid}")->assertForbidden();
        $this->patchJson("/api/visitas/{$visitaUuid}/checkout", [
            'latitude' => $pdv->latitude,
            'longitude' => $pdv->longitude,
        ])->assertForbidden();
    }

    public function test_admin_acessa_visita_de_qualquer_promotor_da_mesma_empresa(): void
    {
        $empresa = Empresa::factory()->create();
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);

        Sanctum::actingAs($promotor);
        $visitaUuid = $this->postJson('/api/visitas', [
            'ponto_venda_uuid' => $pdv->uuid,
            'latitude' => $pdv->latitude,
            'longitude' => $pdv->longitude,
        ])->json('visita.id');

        Sanctum::actingAs($admin);
        $this->getJson("/api/visitas/{$visitaUuid}")->assertOk();
    }

    public function test_promotor_so_ve_as_proprias_visitas_na_listagem_mesmo_pedindo_outro_usuario(): void
    {
        $empresa = Empresa::factory()->create();
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        $outroPromotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);

        Sanctum::actingAs($outroPromotor);
        $this->postJson('/api/visitas', [
            'ponto_venda_uuid' => $pdv->uuid,
            'latitude' => $pdv->latitude,
            'longitude' => $pdv->longitude,
        ])->assertCreated();

        Sanctum::actingAs($promotor);
        // Tenta pedir explicitamente as visitas do outro promotor — o backend ignora e força
        // as próprias (ver VisitaController::index).
        $response = $this->getJson("/api/visitas?usuario_uuid={$outroPromotor->uuid}")->assertOk();

        $this->assertCount(0, $response->json('visitas'));
    }

    public function test_checkin_vincula_campanha_quando_existe_exatamente_uma_ativa_e_vigente(): void
    {
        $empresa = Empresa::factory()->create();
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        $campanha = CampanhaAuditoria::factory()->create(['empresa_id' => $empresa->id]);

        Sanctum::actingAs($promotor);

        $response = $this->postJson('/api/visitas', [
            'ponto_venda_uuid' => $pdv->uuid,
            'latitude' => $pdv->latitude,
            'longitude' => $pdv->longitude,
        ]);

        $response->assertCreated()->assertJsonPath('visita.campanha.id', $campanha->uuid);
    }

    public function test_checkin_nao_vincula_campanha_quando_nao_ha_nenhuma_ativa(): void
    {
        $empresa = Empresa::factory()->create();
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);

        Sanctum::actingAs($promotor);

        $response = $this->postJson('/api/visitas', [
            'ponto_venda_uuid' => $pdv->uuid,
            'latitude' => $pdv->latitude,
            'longitude' => $pdv->longitude,
        ]);

        $response->assertCreated()->assertJsonPath('visita.campanha', null);
    }

    public function test_checkin_nao_vincula_campanha_quando_ha_mais_de_uma_ativa_por_ambiguidade(): void
    {
        $empresa = Empresa::factory()->create();
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        CampanhaAuditoria::factory()->count(2)->create(['empresa_id' => $empresa->id]);

        Sanctum::actingAs($promotor);

        $response = $this->postJson('/api/visitas', [
            'ponto_venda_uuid' => $pdv->uuid,
            'latitude' => $pdv->latitude,
            'longitude' => $pdv->longitude,
        ]);

        $response->assertCreated()->assertJsonPath('visita.campanha', null);
    }

    public function test_checkin_ignora_campanha_expirada_ou_futura_para_vinculo(): void
    {
        $empresa = Empresa::factory()->create();
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        CampanhaAuditoria::factory()->expirada()->create(['empresa_id' => $empresa->id]);
        CampanhaAuditoria::factory()->futura()->create(['empresa_id' => $empresa->id]);

        Sanctum::actingAs($promotor);

        $response = $this->postJson('/api/visitas', [
            'ponto_venda_uuid' => $pdv->uuid,
            'latitude' => $pdv->latitude,
            'longitude' => $pdv->longitude,
        ]);

        $response->assertCreated()->assertJsonPath('visita.campanha', null);
    }

    public function test_lista_de_visitas_filtra_por_status(): void
    {
        $empresa = Empresa::factory()->create();
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);

        Sanctum::actingAs($promotor);
        $abertaUuid = $this->postJson('/api/visitas', [
            'ponto_venda_uuid' => $pdv->uuid,
            'latitude' => $pdv->latitude,
            'longitude' => $pdv->longitude,
        ])->json('visita.id');

        // Outra loja: no mesmo PDV o check-in retomaria a visita aberta em vez de criar outra.
        $pdvB = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        $finalizadaUuid = $this->postJson('/api/visitas', [
            'ponto_venda_uuid' => $pdvB->uuid,
            'latitude' => $pdvB->latitude,
            'longitude' => $pdvB->longitude,
        ])->json('visita.id');
        $this->patchJson("/api/visitas/{$finalizadaUuid}/checkout", [
            'latitude' => $pdvB->latitude,
            'longitude' => $pdvB->longitude,
        ])->assertOk();

        Sanctum::actingAs($admin);
        $response = $this->getJson('/api/visitas?status=FINALIZADA')->assertOk();

        $ids = collect($response->json('visitas'))->pluck('id');
        $this->assertEquals([$finalizadaUuid], $ids->all());
        $this->assertNotContains($abertaUuid, $ids->all());
    }

    /**
     * Idempotência de check-in — ver VisitaController::store e docs/04-APP-MOBILE.md, "Fila
     * offline de envio". Simula o cenário real: o app manda o mesmo `idempotency_key` de novo
     * porque não conseguiu confirmar se a primeira resposta chegou.
     */
    public function test_reenvio_com_mesma_idempotency_key_nao_duplica_a_visita(): void
    {
        $empresa = Empresa::factory()->create();
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        $chave = (string) Str::uuid();

        Sanctum::actingAs($promotor);

        $payload = [
            'ponto_venda_uuid' => $pdv->uuid,
            'latitude' => $pdv->latitude,
            'longitude' => $pdv->longitude,
            'idempotency_key' => $chave,
        ];

        $primeira = $this->postJson('/api/visitas', $payload);
        $primeira->assertCreated();

        $segunda = $this->postJson('/api/visitas', $payload);
        $segunda->assertOk() // 200, não 201 — não criou de novo.
            ->assertJsonPath('visita.id', $primeira->json('visita.id'));

        $this->assertDatabaseCount('visitas', 1);
    }

    public function test_idempotency_key_diferente_cria_visitas_distintas(): void
    {
        $empresa = Empresa::factory()->create();
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        $outroPdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);

        Sanctum::actingAs($promotor);

        $this->postJson('/api/visitas', [
            'ponto_venda_uuid' => $pdv->uuid,
            'latitude' => $pdv->latitude,
            'longitude' => $pdv->longitude,
            'idempotency_key' => (string) Str::uuid(),
        ])->assertCreated();

        // Loja diferente (na mesma loja o check-in retoma a visita aberta — RetomadaEAutorizacaoTest).
        $this->postJson('/api/visitas', [
            'ponto_venda_uuid' => $outroPdv->uuid,
            'latitude' => $outroPdv->latitude,
            'longitude' => $outroPdv->longitude,
            'idempotency_key' => (string) Str::uuid(),
        ])->assertCreated();

        $this->assertDatabaseCount('visitas', 2);
    }

    public function test_idempotency_key_de_outro_promotor_nao_e_reaproveitada(): void
    {
        $empresa = Empresa::factory()->create();
        $dono = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        $outroPromotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        $chave = (string) Str::uuid();

        Sanctum::actingAs($dono);
        $this->postJson('/api/visitas', [
            'ponto_venda_uuid' => $pdv->uuid,
            'latitude' => $pdv->latitude,
            'longitude' => $pdv->longitude,
            'idempotency_key' => $chave,
        ])->assertCreated();

        // Colisão de uuid entre dois clientes é praticamente impossível na prática, mas o
        // controller ainda checa ownership antes de devolver a visita existente.
        Sanctum::actingAs($outroPromotor);
        $this->postJson('/api/visitas', [
            'ponto_venda_uuid' => $pdv->uuid,
            'latitude' => $pdv->latitude,
            'longitude' => $pdv->longitude,
            'idempotency_key' => $chave,
        ])->assertForbidden();
    }

    public function test_checkin_sem_idempotency_key_continua_funcionando(): void
    {
        $empresa = Empresa::factory()->create();
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        $outroPdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);

        Sanctum::actingAs($promotor);

        $this->postJson('/api/visitas', [
            'ponto_venda_uuid' => $pdv->uuid,
            'latitude' => $pdv->latitude,
            'longitude' => $pdv->longitude,
        ])->assertCreated();

        $this->postJson('/api/visitas', [
            'ponto_venda_uuid' => $outroPdv->uuid,
            'latitude' => $outroPdv->latitude,
            'longitude' => $outroPdv->longitude,
        ])->assertCreated();

        $this->assertDatabaseCount('visitas', 2);
    }
}

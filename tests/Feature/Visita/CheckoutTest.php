<?php

namespace Tests\Feature\Visita;

use App\Models\Empresa;
use App\Models\PontoVenda;
use App\Models\Usuario;
use App\Models\Visita;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CheckoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_checkout_de_visita_aberta_finaliza_e_grava_distancia_sem_bloquear(): void
    {
        $empresa = Empresa::factory()->create();
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);

        Sanctum::actingAs($promotor);
        $visitaUuid = $this->postJson('/api/visitas', [
            'ponto_venda_uuid' => $pdv->uuid,
            'latitude' => $pdv->latitude,
            'longitude' => $pdv->longitude,
        ])->json('visita.id');

        // Longe do PDV no checkout — regra de negócio 1 diz que o raio só vale pro check-in,
        // checkout nunca bloqueia por distância (só registra).
        $response = $this->patchJson("/api/visitas/{$visitaUuid}/checkout", [
            'latitude' => $pdv->latitude - 0.5,
            'longitude' => $pdv->longitude,
        ]);

        $response->assertOk()->assertJsonPath('visita.status', 'FINALIZADA');

        $visita = Visita::where('uuid', $visitaUuid)->first();
        $this->assertNotNull($visita->fim_data);
        $this->assertGreaterThan(0, $visita->fim_distancia_metros);
    }

    public function test_checkout_de_visita_ja_finalizada_retorna_422(): void
    {
        $empresa = Empresa::factory()->create();
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);

        Sanctum::actingAs($promotor);
        $visitaUuid = $this->postJson('/api/visitas', [
            'ponto_venda_uuid' => $pdv->uuid,
            'latitude' => $pdv->latitude,
            'longitude' => $pdv->longitude,
        ])->json('visita.id');

        $this->patchJson("/api/visitas/{$visitaUuid}/checkout", [
            'latitude' => $pdv->latitude,
            'longitude' => $pdv->longitude,
        ])->assertOk();

        $this->patchJson("/api/visitas/{$visitaUuid}/checkout", [
            'latitude' => $pdv->latitude,
            'longitude' => $pdv->longitude,
        ])->assertStatus(422);
    }
}

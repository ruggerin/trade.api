<?php

namespace Tests\Feature;

use App\Models\Empresa;
use App\Models\Fatura;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Faturas são registro manual de cobrança (sem gateway de pagamento — decisão de escopo, ver
 * docs/00-VISAO-GERAL.md) — só o SUPERADMIN mexe nisso.
 */
class FaturaTest extends TestCase
{
    use RefreshDatabase;

    public function test_superadmin_cria_fatura_para_empresa(): void
    {
        $empresa = Empresa::factory()->create();
        $superadmin = Usuario::factory()->superadmin()->create();
        Sanctum::actingAs($superadmin);

        $response = $this->postJson("/api/superadmin/empresas/{$empresa->uuid}/faturas", [
            'valor' => 199.90,
            'referencia' => '2026-09-01',
            'vencimento' => '2026-09-10',
        ]);

        $response->assertCreated()
            ->assertJsonPath('fatura.valor', 199.9)
            ->assertJsonPath('fatura.status', 'PENDENTE');

        $this->assertDatabaseHas('faturas', ['empresa_id' => $empresa->id, 'valor' => 199.90]);
    }

    public function test_valor_e_vencimento_sao_obrigatorios(): void
    {
        $empresa = Empresa::factory()->create();
        $superadmin = Usuario::factory()->superadmin()->create();
        Sanctum::actingAs($superadmin);

        $this->postJson("/api/superadmin/empresas/{$empresa->uuid}/faturas", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['valor', 'referencia', 'vencimento']);
    }

    public function test_lista_faturas_de_uma_empresa(): void
    {
        $empresaA = Empresa::factory()->create();
        $empresaB = Empresa::factory()->create();
        Fatura::create([
            'empresa_id' => $empresaA->id, 'valor' => 100, 'referencia' => '2026-08-01', 'vencimento' => '2026-08-10',
        ]);
        Fatura::create([
            'empresa_id' => $empresaB->id, 'valor' => 200, 'referencia' => '2026-08-01', 'vencimento' => '2026-08-10',
        ]);

        $superadmin = Usuario::factory()->superadmin()->create();
        Sanctum::actingAs($superadmin);

        $response = $this->getJson("/api/superadmin/empresas/{$empresaA->uuid}/faturas")->assertOk();

        $this->assertCount(1, $response->json('faturas'));
        // json_encode representa float sem casas decimais como "100" (sem ponto) — decodifica
        // de volta como int, não 100.0. Não é bug do app, é serialização JSON de número inteiro.
        $response->assertJsonPath('faturas.0.valor', 100);
    }

    public function test_marcar_como_paga_sem_informar_data_grava_hoje(): void
    {
        $empresa = Empresa::factory()->create();
        $fatura = Fatura::create([
            'empresa_id' => $empresa->id, 'valor' => 150, 'referencia' => '2026-09-01', 'vencimento' => '2026-09-10',
        ]);
        $superadmin = Usuario::factory()->superadmin()->create();
        Sanctum::actingAs($superadmin);

        $response = $this->putJson(
            "/api/superadmin/empresas/{$empresa->uuid}/faturas/{$fatura->uuid}",
            ['status' => 'PAGA'],
        );

        $response->assertOk()
            ->assertJsonPath('fatura.status', 'PAGA')
            ->assertJsonPath('fatura.pago_em', now()->toDateString());
    }

    public function test_nao_acessa_fatura_de_outra_empresa_pela_rota_aninhada(): void
    {
        $empresaA = Empresa::factory()->create();
        $empresaB = Empresa::factory()->create();
        $faturaDeB = Fatura::create([
            'empresa_id' => $empresaB->id, 'valor' => 100, 'referencia' => '2026-08-01', 'vencimento' => '2026-08-10',
        ]);

        $superadmin = Usuario::factory()->superadmin()->create();
        Sanctum::actingAs($superadmin);

        $this->putJson(
            "/api/superadmin/empresas/{$empresaA->uuid}/faturas/{$faturaDeB->uuid}",
            ['status' => 'PAGA'],
        )->assertNotFound();
    }

    public function test_admin_comum_nao_acessa_faturas(): void
    {
        $empresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($admin);

        $this->getJson("/api/superadmin/empresas/{$empresa->uuid}/faturas")->assertForbidden();
        $this->postJson("/api/superadmin/empresas/{$empresa->uuid}/faturas", [
            'valor' => 100, 'referencia' => '2026-09-01', 'vencimento' => '2026-09-10',
        ])->assertForbidden();
    }
}

<?php

namespace Tests\Feature;

use App\Models\Empresa;
use App\Models\Parametro;
use App\Models\Usuario;
use App\Support\Faturamento;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Faturamento é recurso negociado na adesão do plano (docs/61 §5.2): o SUPERADMIN libera
 * (`empresas.faturamento_contratado`) e só então o ADMIN da empresa liga FATURAMENTO_HABILITADO.
 */
class Doc61FaturamentoContratoTest extends TestCase
{
    use RefreshDatabase;

    private Empresa $empresa;

    protected function setUp(): void
    {
        parent::setUp();
        $this->empresa = Empresa::factory()->create();
        Sanctum::actingAs(Usuario::factory()->admin()->create(['empresa_id' => $this->empresa->id]));
    }

    private function itemDoCatalogo(): array
    {
        $areas = $this->getJson('/api/parametros/catalogo')->assertOk()->json('areas');

        return collect($areas)->flatMap(fn ($a) => $a['itens'])->firstWhere('chave', 'FATURAMENTO_HABILITADO');
    }

    public function test_sem_contrato_o_parametro_vem_travado_e_o_admin_nao_liga(): void
    {
        $this->assertStringContainsString('contratada', $this->itemDoCatalogo()['bloqueado']);

        $this->putJson('/api/parametros/lote', ['alteracoes' => [['chave' => 'FATURAMENTO_HABILITADO', 'valor' => true]]])
            ->assertStatus(422);
        $this->assertFalse(Parametro::where('chave', 'FATURAMENTO_HABILITADO')->where('valor', 'true')->exists());
    }

    public function test_parametro_ligado_sem_contrato_nao_vale(): void
    {
        // Ligado por fora (Avançado, ou antes de o contrato existir): continua sem efeito.
        Parametro::create(['empresa_id' => $this->empresa->id, 'chave' => 'FATURAMENTO_HABILITADO', 'valor' => 'true', 'ativo' => true]);

        $this->assertFalse(Faturamento::habilitado($this->empresa->fresh()));
    }

    public function test_superadmin_libera_e_o_admin_passa_a_poder_ligar(): void
    {
        $superadmin = Usuario::factory()->superadmin()->create();
        $admin = auth()->user();

        Sanctum::actingAs($superadmin);
        $this->putJson("/api/superadmin/empresas/{$this->empresa->uuid}", ['faturamento_contratado' => true])
            ->assertOk()->assertJsonPath('empresa.faturamento_contratado', true);

        Sanctum::actingAs($admin->fresh());
        $this->assertNull($this->itemDoCatalogo()['bloqueado']);
        $this->putJson('/api/parametros/lote', ['alteracoes' => [['chave' => 'FATURAMENTO_HABILITADO', 'valor' => true]]])->assertOk();
        $this->assertTrue(Faturamento::habilitado($this->empresa->fresh()));
    }

    public function test_admin_da_empresa_nao_se_autolibera(): void
    {
        $this->putJson('/api/empresa', ['faturamento_contratado' => true]);

        $this->assertFalse($this->empresa->fresh()->faturamento_contratado);
    }
}

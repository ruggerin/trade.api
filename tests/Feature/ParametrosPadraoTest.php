<?php

namespace Tests\Feature;

use App\Models\Empresa;
use App\Models\Parametro;
use App\Models\Usuario;
use App\Support\ParametrosPadrao;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Completar os parâmetros padrão de uma empresa (App\Support\ParametrosPadrao) — botão do
 * superadmin e comando `parametros:completar`. Só cria o que falta; nunca sobrescreve nem reativa.
 */
class ParametrosPadraoTest extends TestCase
{
    use RefreshDatabase;

    public function test_superadmin_ve_o_que_falta_e_completa_sem_mexer_no_que_existe(): void
    {
        $empresa = Empresa::factory()->create();
        // Valor customizado e parâmetro DESATIVADO (raio desativado = sem limite): os dois ficam como estão.
        Parametro::withoutGlobalScopes()->create(['empresa_id' => $empresa->id, 'chave' => 'CONTRATO_AVISO_DIAS', 'valor' => '45', 'ativo' => true]);
        Parametro::withoutGlobalScopes()->create(['empresa_id' => $empresa->id, 'chave' => 'CHECKIN_RAIO_METROS', 'valor' => '500', 'ativo' => false]);

        Sanctum::actingAs(Usuario::factory()->superadmin()->create());
        $url = "/api/superadmin/empresas/{$empresa->uuid}/parametros-padrao";

        $situacao = collect($this->getJson($url)->assertOk()->json('parametros'));
        $this->assertCount(count(ParametrosPadrao::CATALOGO), $situacao);
        $this->assertTrue($situacao->firstWhere('chave', 'CONTRATO_AVISO_DIAS')['cadastrado']);
        $this->assertFalse($situacao->firstWhere('chave', 'JORNADA_INICIO')['cadastrado']);

        $criados = $this->postJson($url)->assertOk()->json('criados');
        $this->assertCount(count(ParametrosPadrao::CATALOGO) - 2, $criados);
        $this->assertContains('JORNADA_INICIO', $criados);
        $this->assertNotContains('CHECKIN_RAIO_METROS', $criados);

        $this->assertDatabaseHas('parametros', ['empresa_id' => $empresa->id, 'chave' => 'CONTRATO_AVISO_DIAS', 'valor' => '45']);
        $this->assertDatabaseHas('parametros', ['empresa_id' => $empresa->id, 'chave' => 'CHECKIN_RAIO_METROS', 'valor' => '500', 'ativo' => false]);
        $this->assertDatabaseHas('parametros', ['empresa_id' => $empresa->id, 'chave' => 'JORNADA_INICIO', 'valor' => '07:00', 'ativo' => true]);

        // Idempotente.
        $this->postJson($url)->assertOk()->assertJsonPath('criados', []);
    }

    public function test_so_superadmin_acessa(): void
    {
        $empresa = Empresa::factory()->create();
        Sanctum::actingAs(Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]));

        $this->postJson("/api/superadmin/empresas/{$empresa->uuid}/parametros-padrao")->assertForbidden();
        $this->assertSame(0, Parametro::withoutGlobalScopes()->where('empresa_id', $empresa->id)->count());
    }

    public function test_comando_completa_todas_as_empresas_e_simular_nao_grava(): void
    {
        $a = Empresa::factory()->create();
        $b = Empresa::factory()->create();
        $total = count(ParametrosPadrao::CATALOGO);

        $this->artisan('parametros:completar --simular')->assertSuccessful();
        $this->assertSame(0, Parametro::withoutGlobalScopes()->count());

        $this->artisan('parametros:completar')->assertSuccessful();
        $this->assertSame($total, Parametro::withoutGlobalScopes()->where('empresa_id', $a->id)->count());
        $this->assertSame($total, Parametro::withoutGlobalScopes()->where('empresa_id', $b->id)->count());
    }
}

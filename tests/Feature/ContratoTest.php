<?php

namespace Tests\Feature;

use App\Models\Contrato;
use App\Models\Empresa;
use App\Models\PontoVenda;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * docs/02-API-BACKEND.md#contratos — comodato de expositor e ponto extra entre a empresa e um
 * PDV. ADMIN/GESTOR (com permissão) cadastram na própria empresa; SUPERADMIN cadastra/edita em
 * qualquer empresa via `empresa_uuid` (suporte), mesmo padrão de usuarios.gerenciar — nunca em
 * DELETE. Upload do arquivo assinado é ação separada da criação.
 */
class ContratoTest extends TestCase
{
    use RefreshDatabase;

    // GD não está habilitado neste ambiente — sobe um PNG 1x1 real mínimo, mesmo truque de
    // tests/Feature/Visita/RegistroTest.php.
    private function arquivoFake(): UploadedFile
    {
        $conteudo = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
        );
        $caminho = tempnam(sys_get_temp_dir(), 'contrato').'.png';
        file_put_contents($caminho, $conteudo);

        return new UploadedFile($caminho, 'contrato-assinado.png', 'image/png', null, true);
    }

    public function test_admin_cria_contrato_na_propria_empresa(): void
    {
        $empresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/contratos', [
            'ponto_venda_uuid' => $pdv->uuid,
            'tipo' => 'COMODATO',
            'descricao' => 'Freezer 2 portas',
            'vigencia_inicio' => '2026-01-01',
            'vigencia_fim' => '2026-12-31',
        ]);

        $response->assertCreated()
            ->assertJsonPath('contrato.tipo', 'COMODATO')
            ->assertJsonPath('contrato.ponto_venda.id', $pdv->uuid);
        $this->assertDatabaseHas('contratos', ['empresa_id' => $empresa->id, 'ponto_venda_id' => $pdv->id]);
    }

    public function test_admin_nao_vincula_contrato_a_pdv_de_outra_empresa(): void
    {
        $empresa = Empresa::factory()->create();
        $outraEmpresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        $pdvAlheio = PontoVenda::factory()->create(['empresa_id' => $outraEmpresa->id]);
        Sanctum::actingAs($admin);

        $this->postJson('/api/contratos', [
            'ponto_venda_uuid' => $pdvAlheio->uuid,
            'tipo' => 'PONTO_EXTRA',
            'vigencia_inicio' => '2026-01-01',
            'vigencia_fim' => '2026-12-31',
        ])->assertStatus(422)->assertJsonValidationErrors('ponto_venda_uuid');
    }

    public function test_gestor_sem_permissao_e_bloqueado(): void
    {
        $empresa = Empresa::factory()->create();
        $gestor = Usuario::factory()->gestor()->create(['empresa_id' => $empresa->id]);
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($gestor);

        $this->postJson('/api/contratos', [
            'ponto_venda_uuid' => $pdv->uuid,
            'tipo' => 'COMODATO',
            'vigencia_inicio' => '2026-01-01',
            'vigencia_fim' => '2026-12-31',
        ])->assertForbidden();
    }

    public function test_promotor_nunca_acessa_contratos(): void
    {
        $empresa = Empresa::factory()->create();
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($promotor);

        $this->getJson('/api/contratos')->assertForbidden();
    }

    public function test_superadmin_cria_contrato_em_qualquer_empresa_via_empresa_uuid(): void
    {
        $empresa = Empresa::factory()->create();
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        $superadmin = Usuario::factory()->superadmin()->create();
        Sanctum::actingAs($superadmin);

        $response = $this->postJson('/api/contratos', [
            'empresa_uuid' => $empresa->uuid,
            'ponto_venda_uuid' => $pdv->uuid,
            'tipo' => 'PONTO_EXTRA',
            'vigencia_inicio' => '2026-01-01',
            'vigencia_fim' => '2026-06-30',
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('contratos', ['empresa_id' => $empresa->id]);
    }

    public function test_superadmin_sem_empresa_uuid_recebe_422(): void
    {
        $empresa = Empresa::factory()->create();
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        $superadmin = Usuario::factory()->superadmin()->create();
        Sanctum::actingAs($superadmin);

        $this->postJson('/api/contratos', [
            'ponto_venda_uuid' => $pdv->uuid,
            'tipo' => 'COMODATO',
            'vigencia_inicio' => '2026-01-01',
            'vigencia_fim' => '2026-12-31',
        ])->assertStatus(422)->assertJsonValidationErrors('empresa_uuid');
    }

    public function test_superadmin_nao_pode_deletar_contrato(): void
    {
        $empresa = Empresa::factory()->create();
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        $contrato = Contrato::create([
            'empresa_id' => $empresa->id,
            'ponto_venda_id' => $pdv->id,
            'tipo' => 'COMODATO',
            'vigencia_inicio' => '2026-01-01',
            'vigencia_fim' => '2026-12-31',
        ]);
        $superadmin = Usuario::factory()->superadmin()->create();
        Sanctum::actingAs($superadmin);

        $this->deleteJson("/api/contratos/{$contrato->uuid}")->assertForbidden();
    }

    public function test_admin_desativa_contrato(): void
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
        $this->assertDatabaseHas('contratos', ['id' => $contrato->id, 'ativo' => false]);
    }

    public function test_upload_e_download_do_arquivo_assinado(): void
    {
        Storage::fake('local');

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

        $response = $this->postJson("/api/contratos/{$contrato->uuid}/arquivo", [
            'arquivo' => $this->arquivoFake(),
        ]);

        $response->assertOk();
        $this->assertNotNull($response->json('contrato.arquivo_url'));
        Storage::disk('local')->assertExists($contrato->fresh()->arquivo_path);

        $this->get("/api/contratos/{$contrato->uuid}/arquivo")->assertOk();
    }

    public function test_filtro_por_tipo_e_ponto_venda(): void
    {
        $empresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        $pdvA = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        $pdvB = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        Contrato::create([
            'empresa_id' => $empresa->id, 'ponto_venda_id' => $pdvA->id, 'tipo' => 'COMODATO',
            'vigencia_inicio' => '2026-01-01', 'vigencia_fim' => '2026-12-31',
        ]);
        Contrato::create([
            'empresa_id' => $empresa->id, 'ponto_venda_id' => $pdvB->id, 'tipo' => 'PONTO_EXTRA',
            'vigencia_inicio' => '2026-01-01', 'vigencia_fim' => '2026-12-31',
        ]);
        Sanctum::actingAs($admin);

        $this->getJson('/api/contratos?tipo=PONTO_EXTRA')
            ->assertOk()
            ->assertJsonCount(1, 'contratos')
            ->assertJsonPath('contratos.0.tipo', 'PONTO_EXTRA');

        $this->getJson("/api/contratos?ponto_venda_uuid={$pdvA->uuid}")
            ->assertOk()
            ->assertJsonCount(1, 'contratos')
            ->assertJsonPath('contratos.0.ponto_venda.id', $pdvA->uuid);
    }
}

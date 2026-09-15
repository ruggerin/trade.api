<?php

namespace Tests\Feature\PontoVenda;

use App\Models\Empresa;
use App\Models\PontoVenda;
use App\Models\RamoAtividade;
use App\Models\RedeLoja;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Rede de Lojas, Ramo de Atividade (selects, ver App\Models\RedeLoja/RamoAtividade), número de
 * checkouts e a foto da fachada — campos novos no cadastro de PontoVenda, pedido explícito do
 * usuário ("preciso além desses dois novos selects, um lugar pra fazer upload da faixada e
 * número de checkouts").
 */
class ClassificacaoEFachadaTest extends TestCase
{
    use RefreshDatabase;

    // GD não está habilitado neste ambiente (UploadedFile::fake()->image() precisa dela) — sobe
    // um PNG 1x1 real e mínimo, mesmo truque de tests/Feature/Auth/FotoPerfilTest.php.
    private function imagemFake(string $nome = 'fachada.png'): UploadedFile
    {
        $conteudo = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
        );
        $caminho = tempnam(sys_get_temp_dir(), 'fachada').'.png';
        file_put_contents($caminho, $conteudo);

        return new UploadedFile($caminho, $nome, 'image/png', null, true);
    }

    public function test_cria_ponto_venda_com_rede_ramo_e_numero_de_checkouts(): void
    {
        $empresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        $rede = RedeLoja::factory()->create(['empresa_id' => $empresa->id]);
        $ramo = RamoAtividade::factory()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/pontos-venda', [
            'razao_social' => 'Comercial Teste LTDA',
            'fantasia' => 'Comercial Teste',
            'latitude' => -3.1019,
            'longitude' => -60.0250,
            'endereco' => 'Rua Teste, 123',
            'cidade' => 'Manaus',
            'rede_loja_uuid' => $rede->uuid,
            'ramo_atividade_uuid' => $ramo->uuid,
            'numero_checkouts' => 5,
        ]);

        $response->assertCreated()
            ->assertJsonPath('ponto_venda.rede_loja.id', $rede->uuid)
            ->assertJsonPath('ponto_venda.ramo_atividade.id', $ramo->uuid)
            ->assertJsonPath('ponto_venda.numero_checkouts', 5);
    }

    public function test_rede_ou_ramo_de_outra_empresa_e_rejeitado(): void
    {
        $empresa = Empresa::factory()->create();
        $outraEmpresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        $redeDeOutraEmpresa = RedeLoja::factory()->create(['empresa_id' => $outraEmpresa->id]);
        Sanctum::actingAs($admin);

        $this->postJson('/api/pontos-venda', [
            'razao_social' => 'Comercial Teste LTDA',
            'fantasia' => 'Comercial Teste',
            'latitude' => -3.1019,
            'longitude' => -60.0250,
            'endereco' => 'Rua Teste, 123',
            'cidade' => 'Manaus',
            'rede_loja_uuid' => $redeDeOutraEmpresa->uuid,
        ])->assertStatus(422)->assertJsonValidationErrors('rede_loja_uuid');
    }

    public function test_atualiza_para_remover_rede_e_ramo_ja_atribuidos(): void
    {
        $empresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        $rede = RedeLoja::factory()->create(['empresa_id' => $empresa->id]);
        $ramo = RamoAtividade::factory()->create(['empresa_id' => $empresa->id]);
        $pontoVenda = PontoVenda::factory()->create([
            'empresa_id' => $empresa->id,
            'rede_loja_id' => $rede->id,
            'ramo_atividade_id' => $ramo->id,
        ]);
        Sanctum::actingAs($admin);

        $response = $this->putJson("/api/pontos-venda/{$pontoVenda->uuid}", [
            'rede_loja_uuid' => null,
            'ramo_atividade_uuid' => null,
        ]);

        $response->assertOk()
            ->assertJsonPath('ponto_venda.rede_loja', null)
            ->assertJsonPath('ponto_venda.ramo_atividade', null);
        $this->assertNull($pontoVenda->refresh()->rede_loja_id);
        $this->assertNull($pontoVenda->refresh()->ramo_atividade_id);
    }

    public function test_admin_envia_a_foto_da_fachada(): void
    {
        Storage::fake('local');
        $empresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        $pontoVenda = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($admin);

        $response = $this->postJson("/api/pontos-venda/{$pontoVenda->uuid}/fachada", ['imagem' => $this->imagemFake()]);

        $response->assertOk();
        $this->assertNotNull($response->json('ponto_venda.fachada_url'));
        $pontoVenda->refresh();
        $this->assertNotNull($pontoVenda->fachada_path);
        Storage::disk('local')->assertExists($pontoVenda->fachada_path);
    }

    public function test_enviar_nova_fachada_substitui_a_anterior_sem_deixar_lixo(): void
    {
        Storage::fake('local');
        $empresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        $pontoVenda = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($admin);

        $this->postJson("/api/pontos-venda/{$pontoVenda->uuid}/fachada", ['imagem' => $this->imagemFake('primeira.png')])->assertOk();
        $pontoVenda->refresh();
        $caminhoAntigo = $pontoVenda->fachada_path;

        $this->postJson("/api/pontos-venda/{$pontoVenda->uuid}/fachada", ['imagem' => $this->imagemFake('segunda.png')])->assertOk();
        $pontoVenda->refresh();

        $this->assertNotSame($caminhoAntigo, $pontoVenda->fachada_path);
        Storage::disk('local')->assertMissing($caminhoAntigo);
        Storage::disk('local')->assertExists($pontoVenda->fachada_path);
    }

    public function test_promotor_nao_pode_enviar_fachada(): void
    {
        $empresa = Empresa::factory()->create();
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        $pontoVenda = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($promotor);

        $this->postJson("/api/pontos-venda/{$pontoVenda->uuid}/fachada", ['imagem' => $this->imagemFake()])
            ->assertForbidden();
    }

    public function test_admin_remove_a_fachada(): void
    {
        Storage::fake('local');
        $empresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        $pontoVenda = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($admin);
        $this->postJson("/api/pontos-venda/{$pontoVenda->uuid}/fachada", ['imagem' => $this->imagemFake()])->assertOk();
        $pontoVenda->refresh();
        $caminho = $pontoVenda->fachada_path;

        $response = $this->deleteJson("/api/pontos-venda/{$pontoVenda->uuid}/fachada");

        $response->assertOk()->assertJsonPath('ponto_venda.fachada_url', null);
        Storage::disk('local')->assertMissing($caminho);
        $this->assertNull($pontoVenda->refresh()->fachada_path);
    }

    public function test_fachada_de_ponto_venda_sem_foto_retorna_404(): void
    {
        $empresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        $pontoVenda = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($admin);

        $this->getJson("/api/pontos-venda/{$pontoVenda->uuid}/fachada")->assertNotFound();
    }

    public function test_qualquer_autenticado_da_empresa_ve_a_fachada(): void
    {
        Storage::fake('local');
        $empresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        $pontoVenda = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($admin);
        $this->postJson("/api/pontos-venda/{$pontoVenda->uuid}/fachada", ['imagem' => $this->imagemFake()])->assertOk();

        Sanctum::actingAs($promotor);
        $this->getJson("/api/pontos-venda/{$pontoVenda->uuid}/fachada")->assertOk();
    }
}

<?php

namespace Tests\Feature;

use App\Models\Empresa;
use App\Models\Planograma;
use App\Models\PlanogramaBloco;
use App\Models\PlanogramaPrateleira;
use App\Models\ProdutoAuditoria;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * docs/22-PLANOGRAMA.md — referência visual de layout (grade de blocos, largura variável),
 * mesma permissão de catálogo (catalogo.gerenciar, dado satélite).
 */
class PlanogramaTest extends TestCase
{
    use RefreshDatabase;

    // GD não está habilitado neste ambiente — sobe um PNG 1x1 real mínimo, mesmo truque de
    // ContratoTest::arquivoFake.
    private function fotoFake(): UploadedFile
    {
        $conteudo = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
        );
        $caminho = tempnam(sys_get_temp_dir(), 'planograma').'.png';
        file_put_contents($caminho, $conteudo);

        return new UploadedFile($caminho, 'capa.png', 'image/png', null, true);
    }

    public function test_admin_cria_planograma(): void
    {
        $empresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/planogramas', ['descricao' => 'Linha Topázio']);

        $response->assertCreated()->assertJsonPath('planograma.descricao', 'Linha Topázio');
        $this->assertDatabaseHas('planogramas', ['descricao' => 'Linha Topázio', 'empresa_id' => $empresa->id]);
    }

    public function test_promotor_le_mas_nao_cria(): void
    {
        $empresa = Empresa::factory()->create();
        Planograma::factory()->create(['empresa_id' => $empresa->id, 'descricao' => 'Gôndola Refrigerantes']);
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($promotor);

        $this->getJson('/api/planogramas')->assertOk()->assertJsonCount(1, 'planogramas');
        $this->postJson('/api/planogramas', ['descricao' => 'Outro'])->assertForbidden();
    }

    public function test_isolamento_por_empresa(): void
    {
        $empresaA = Empresa::factory()->create();
        $empresaB = Empresa::factory()->create();
        Planograma::factory()->create(['empresa_id' => $empresaA->id]);
        Planograma::factory()->create(['empresa_id' => $empresaB->id]);

        $adminA = Usuario::factory()->admin()->create(['empresa_id' => $empresaA->id]);
        Sanctum::actingAs($adminA);

        $this->getJson('/api/planogramas')->assertOk()->assertJsonCount(1, 'planogramas');
    }

    public function test_superadmin_filtra_por_empresa(): void
    {
        $empresaA = Empresa::factory()->create();
        $empresaB = Empresa::factory()->create();
        Planograma::factory()->create(['empresa_id' => $empresaA->id]);
        Planograma::factory()->create(['empresa_id' => $empresaB->id]);

        $superadmin = Usuario::factory()->superadmin()->create();
        Sanctum::actingAs($superadmin);

        $this->getJson('/api/planogramas')->assertOk()->assertJsonCount(2, 'planogramas');
        $this->getJson("/api/planogramas?empresa_uuid={$empresaA->uuid}")->assertOk()->assertJsonCount(1, 'planogramas');
    }

    public function test_destroy_e_soft_delete(): void
    {
        $empresa = Empresa::factory()->create();
        $planograma = Planograma::factory()->create(['empresa_id' => $empresa->id]);
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($admin);

        $this->deleteJson("/api/planogramas/{$planograma->uuid}")->assertNoContent();
        $this->assertDatabaseHas('planogramas', ['id' => $planograma->id, 'ativo' => false]);
    }

    public function test_show_traz_prateleiras_e_blocos(): void
    {
        $empresa = Empresa::factory()->create();
        $planograma = Planograma::factory()->create(['empresa_id' => $empresa->id]);
        $prateleira = PlanogramaPrateleira::create(['planograma_id' => $planograma->id, 'ordem' => 0, 'quantidade_blocos' => 5]);
        $produto = ProdutoAuditoria::factory()->create(['empresa_id' => $empresa->id]);
        PlanogramaBloco::create(['prateleira_id' => $prateleira->id, 'posicao_inicio' => 0, 'largura' => 2, 'produto_auditoria_id' => $produto->id]);

        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($admin);

        $response = $this->getJson("/api/planogramas/{$planograma->uuid}")->assertOk();
        $response->assertJsonCount(1, 'planograma.prateleiras')
            ->assertJsonCount(1, 'planograma.prateleiras.0.blocos')
            ->assertJsonPath('planograma.prateleiras.0.blocos.0.largura', 2)
            ->assertJsonPath('planograma.prateleiras.0.blocos.0.produto_auditoria.id', $produto->uuid);
    }

    public function test_cria_prateleira(): void
    {
        $empresa = Empresa::factory()->create();
        $planograma = Planograma::factory()->create(['empresa_id' => $empresa->id]);
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($admin);

        $response = $this->postJson("/api/planogramas/{$planograma->uuid}/prateleiras", [
            'descricao' => 'Nível 1',
            'quantidade_blocos' => 9,
        ]);

        $response->assertCreated()->assertJsonPath('prateleira.quantidade_blocos', 9);
    }

    public function test_reduzir_quantidade_blocos_com_produto_alem_do_limite_exige_confirmacao(): void
    {
        $empresa = Empresa::factory()->create();
        $planograma = Planograma::factory()->create(['empresa_id' => $empresa->id]);
        $prateleira = PlanogramaPrateleira::create(['planograma_id' => $planograma->id, 'ordem' => 0, 'quantidade_blocos' => 7]);
        $produto = ProdutoAuditoria::factory()->create(['empresa_id' => $empresa->id]);
        PlanogramaBloco::create(['prateleira_id' => $prateleira->id, 'posicao_inicio' => 5, 'largura' => 2, 'produto_auditoria_id' => $produto->id]);

        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($admin);

        // Sem force: 422, avisa o que seria removido, nada muda.
        $response = $this->putJson("/api/planogramas/{$planograma->uuid}/prateleiras/{$prateleira->uuid}", [
            'quantidade_blocos' => 5,
        ]);
        $response->assertStatus(422)->assertJsonCount(1, 'blocos_removidos');
        $this->assertDatabaseHas('planograma_blocos', ['prateleira_id' => $prateleira->id]);

        // Com force=true: aplica e remove o bloco que ultrapassava.
        $this->putJson("/api/planogramas/{$planograma->uuid}/prateleiras/{$prateleira->uuid}", [
            'quantidade_blocos' => 5,
            'force' => true,
        ])->assertOk()->assertJsonCount(0, 'prateleira.blocos');
        $this->assertDatabaseMissing('planograma_blocos', ['prateleira_id' => $prateleira->id]);
    }

    public function test_reduzir_quantidade_blocos_sem_afetar_nenhum_bloco_nao_exige_confirmacao(): void
    {
        $empresa = Empresa::factory()->create();
        $planograma = Planograma::factory()->create(['empresa_id' => $empresa->id]);
        $prateleira = PlanogramaPrateleira::create(['planograma_id' => $planograma->id, 'ordem' => 0, 'quantidade_blocos' => 9]);
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($admin);

        $this->putJson("/api/planogramas/{$planograma->uuid}/prateleiras/{$prateleira->uuid}", [
            'quantidade_blocos' => 5,
        ])->assertOk()->assertJsonPath('prateleira.quantidade_blocos', 5);
    }

    public function test_cria_blocos_em_lote_com_mesmo_produto(): void
    {
        $empresa = Empresa::factory()->create();
        $planograma = Planograma::factory()->create(['empresa_id' => $empresa->id]);
        $prateleira = PlanogramaPrateleira::create(['planograma_id' => $planograma->id, 'ordem' => 0, 'quantidade_blocos' => 9]);
        $produto = ProdutoAuditoria::factory()->create(['empresa_id' => $empresa->id]);
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($admin);

        $response = $this->postJson("/api/planogramas/{$planograma->uuid}/prateleiras/{$prateleira->uuid}/blocos", [
            'produto_auditoria_uuid' => $produto->uuid,
            'blocos' => [
                ['posicao_inicio' => 0, 'largura' => 1],
                ['posicao_inicio' => 1, 'largura' => 1],
                ['posicao_inicio' => 2, 'largura' => 1],
            ],
        ]);

        $response->assertCreated()->assertJsonCount(3, 'blocos');
        $this->assertSame(3, PlanogramaBloco::where('prateleira_id', $prateleira->id)->count());
    }

    public function test_bloco_que_ultrapassa_quantidade_de_blocos_retorna_422(): void
    {
        $empresa = Empresa::factory()->create();
        $planograma = Planograma::factory()->create(['empresa_id' => $empresa->id]);
        $prateleira = PlanogramaPrateleira::create(['planograma_id' => $planograma->id, 'ordem' => 0, 'quantidade_blocos' => 3]);
        $produto = ProdutoAuditoria::factory()->create(['empresa_id' => $empresa->id]);
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($admin);

        $this->postJson("/api/planogramas/{$planograma->uuid}/prateleiras/{$prateleira->uuid}/blocos", [
            'produto_auditoria_uuid' => $produto->uuid,
            'blocos' => [['posicao_inicio' => 2, 'largura' => 2]],
        ])->assertStatus(422)->assertJsonValidationErrors('blocos.0');
    }

    public function test_bloco_sobreposto_a_bloco_existente_retorna_422(): void
    {
        $empresa = Empresa::factory()->create();
        $planograma = Planograma::factory()->create(['empresa_id' => $empresa->id]);
        $prateleira = PlanogramaPrateleira::create(['planograma_id' => $planograma->id, 'ordem' => 0, 'quantidade_blocos' => 9]);
        $produto = ProdutoAuditoria::factory()->create(['empresa_id' => $empresa->id]);
        PlanogramaBloco::create(['prateleira_id' => $prateleira->id, 'posicao_inicio' => 2, 'largura' => 3, 'produto_auditoria_id' => $produto->id]);

        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($admin);

        // Novo bloco em [4,5) sobrepõe o existente [2,5).
        $this->postJson("/api/planogramas/{$planograma->uuid}/prateleiras/{$prateleira->uuid}/blocos", [
            'produto_auditoria_uuid' => $produto->uuid,
            'blocos' => [['posicao_inicio' => 4, 'largura' => 1]],
        ])->assertStatus(422)->assertJsonValidationErrors('blocos.0');
    }

    public function test_move_bloco_existente(): void
    {
        $empresa = Empresa::factory()->create();
        $planograma = Planograma::factory()->create(['empresa_id' => $empresa->id]);
        $prateleira = PlanogramaPrateleira::create(['planograma_id' => $planograma->id, 'ordem' => 0, 'quantidade_blocos' => 9]);
        $produto = ProdutoAuditoria::factory()->create(['empresa_id' => $empresa->id]);
        $bloco = PlanogramaBloco::create(['prateleira_id' => $prateleira->id, 'posicao_inicio' => 0, 'largura' => 1, 'produto_auditoria_id' => $produto->id]);

        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($admin);

        $this->putJson("/api/planogramas/{$planograma->uuid}/prateleiras/{$prateleira->uuid}/blocos/{$bloco->uuid}", [
            'posicao_inicio' => 5,
            'largura' => 3,
        ])->assertOk()->assertJsonPath('bloco.posicao_inicio', 5)->assertJsonPath('bloco.largura', 3);
    }

    public function test_remove_bloco(): void
    {
        $empresa = Empresa::factory()->create();
        $planograma = Planograma::factory()->create(['empresa_id' => $empresa->id]);
        $prateleira = PlanogramaPrateleira::create(['planograma_id' => $planograma->id, 'ordem' => 0, 'quantidade_blocos' => 9]);
        $produto = ProdutoAuditoria::factory()->create(['empresa_id' => $empresa->id]);
        $bloco = PlanogramaBloco::create(['prateleira_id' => $prateleira->id, 'posicao_inicio' => 0, 'largura' => 1, 'produto_auditoria_id' => $produto->id]);

        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($admin);

        $this->deleteJson("/api/planogramas/{$planograma->uuid}/prateleiras/{$prateleira->uuid}/blocos/{$bloco->uuid}")->assertNoContent();
        $this->assertDatabaseMissing('planograma_blocos', ['id' => $bloco->id]);
    }

    public function test_remover_prateleira_remove_blocos_em_cascata(): void
    {
        $empresa = Empresa::factory()->create();
        $planograma = Planograma::factory()->create(['empresa_id' => $empresa->id]);
        $prateleira = PlanogramaPrateleira::create(['planograma_id' => $planograma->id, 'ordem' => 0, 'quantidade_blocos' => 9]);
        $produto = ProdutoAuditoria::factory()->create(['empresa_id' => $empresa->id]);
        $bloco = PlanogramaBloco::create(['prateleira_id' => $prateleira->id, 'posicao_inicio' => 0, 'largura' => 1, 'produto_auditoria_id' => $produto->id]);

        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($admin);

        $this->deleteJson("/api/planogramas/{$planograma->uuid}/prateleiras/{$prateleira->uuid}")->assertNoContent();
        $this->assertDatabaseMissing('planograma_prateleiras', ['id' => $prateleira->id]);
        $this->assertDatabaseMissing('planograma_blocos', ['id' => $bloco->id]);
    }

    public function test_admin_sobe_e_promotor_le_a_foto_capa(): void
    {
        Storage::fake('local');
        $empresa = Empresa::factory()->create();
        $planograma = Planograma::factory()->create(['empresa_id' => $empresa->id]);
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($admin);

        $response = $this->postJson("/api/planogramas/{$planograma->uuid}/foto-capa", ['foto' => $this->fotoFake()]);

        $response->assertOk();
        $this->assertNotNull($response->json('planograma.foto_capa_url'));
        Storage::disk('local')->assertExists($planograma->fresh()->foto_capa_path);

        // Promotor (sem permissão de escrita nenhuma) consegue ler a capa — é consumo, não
        // gestão. Ver docs/22-PLANOGRAMA.md.
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($promotor);
        $this->get("/api/planogramas/{$planograma->uuid}/foto-capa")->assertOk();
    }

    public function test_promotor_nao_sobe_foto_capa(): void
    {
        $empresa = Empresa::factory()->create();
        $planograma = Planograma::factory()->create(['empresa_id' => $empresa->id]);
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($promotor);

        $this->postJson("/api/planogramas/{$planograma->uuid}/foto-capa", ['foto' => $this->fotoFake()])->assertForbidden();
    }

    public function test_proxy_imagem_repassa_imagem_externa(): void
    {
        Http::fake([
            'example.com/*' => Http::response('conteudo-fake-png', 200, ['Content-Type' => 'image/png']),
        ]);
        $empresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($admin);

        $response = $this->get('/api/planogramas/proxy-imagem?url='.urlencode('https://example.com/produto.png'));

        $response->assertOk();
        $this->assertSame('conteudo-fake-png', $response->getContent());
        $this->assertStringStartsWith('image/', $response->headers->get('Content-Type'));
    }

    public function test_proxy_imagem_recusa_url_nao_https(): void
    {
        $empresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($admin);

        $this->get('/api/planogramas/proxy-imagem?url='.urlencode('http://exemplo-catalogo.test/produto.png'))
            ->assertStatus(422);
    }

    // Proteção contra SSRF — recusa mirar IP privado/reservado (ex.: sondar rede interna),
    // mesmo vindo como https válido. Ver PlanogramaController::proxyImagem.
    public function test_proxy_imagem_recusa_ip_privado(): void
    {
        $empresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($admin);

        $this->get('/api/planogramas/proxy-imagem?url='.urlencode('https://127.0.0.1/produto.png'))
            ->assertStatus(422);
    }

    public function test_proxy_imagem_recusa_conteudo_que_nao_e_imagem(): void
    {
        Http::fake([
            'example.com/*' => Http::response('<html>não é imagem</html>', 200, ['Content-Type' => 'text/html']),
        ]);
        $empresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($admin);

        $this->get('/api/planogramas/proxy-imagem?url='.urlencode('https://example.com/pagina.html'))
            ->assertStatus(422);
    }
}

<?php

namespace Tests\Feature\PontoVenda;

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
 * Aba "Dados cadastrais" do app do promotor: contratos vigentes em lista (só título/tipo/vigência)
 * e envio da fachada pelo próprio promotor quando a loja ainda não tem foto.
 */
class AbasDaLojaTest extends TestCase
{
    use RefreshDatabase;

    private function imagemFake(): UploadedFile
    {
        $conteudo = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=');
        $caminho = tempnam(sys_get_temp_dir(), 'fachada').'.png';
        file_put_contents($caminho, $conteudo);

        return new UploadedFile($caminho, 'fachada.png', 'image/png', null, true);
    }

    public function test_detalhe_da_loja_lista_so_os_contratos_ativos_sem_dados_sensiveis(): void
    {
        $empresa = Empresa::factory()->create();
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        Contrato::factory()->create(['empresa_id' => $empresa->id, 'ponto_venda_id' => $pdv->id, 'descricao' => 'Geladeira Panasonic']);
        Contrato::factory()->inativo()->create(['empresa_id' => $empresa->id, 'ponto_venda_id' => $pdv->id, 'descricao' => 'Contrato antigo']);
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        $pdv->promotores()->attach($promotor->id);
        Sanctum::actingAs($promotor);

        $r = $this->getJson("/api/pontos-venda/{$pdv->uuid}")->assertOk();

        $r->assertJsonCount(1, 'ponto_venda.contratos_ativos')
            ->assertJsonPath('ponto_venda.contratos_ativos.0.titulo', 'Geladeira Panasonic')
            ->assertJsonPath('ponto_venda.contratos_ativos.0.tipo', 'COMODATO')
            ->assertJsonMissingPath('ponto_venda.contratos_ativos.0.arquivo_path')
            ->assertJsonMissingPath('ponto_venda.contratos_ativos.0.metas');
    }

    public function test_listagem_tambem_traz_os_contratos_ativos(): void
    {
        $empresa = Empresa::factory()->create();
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        Contrato::factory()->create(['empresa_id' => $empresa->id, 'ponto_venda_id' => $pdv->id, 'descricao' => 'Ponta de gôndola']);
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        $pdv->promotores()->attach($promotor->id);
        Sanctum::actingAs($promotor);

        $this->getJson('/api/pontos-venda')->assertOk()
            ->assertJsonPath('pontos_venda.0.contratos_ativos.0.titulo', 'Ponta de gôndola');
    }

    public function test_promotor_envia_a_fachada_quando_a_loja_nao_tem(): void
    {
        Storage::fake('local');
        $empresa = Empresa::factory()->create();
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        $pdv->promotores()->attach($promotor->id);
        Sanctum::actingAs($promotor);

        $r = $this->postJson("/api/pontos-venda/{$pdv->uuid}/fachada-promotor", ['imagem' => $this->imagemFake()])->assertOk();

        $this->assertNotNull($r->json('ponto_venda.fachada_url'));
        $this->assertNotNull($pdv->refresh()->fachada_path);
        Storage::disk('local')->assertExists($pdv->fachada_path);
    }

    public function test_promotor_nao_substitui_fachada_que_ja_existe(): void
    {
        Storage::fake('local');
        $empresa = Empresa::factory()->create();
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id, 'fachada_path' => 'pontos-venda/x/original.png']);
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        $pdv->promotores()->attach($promotor->id);
        Sanctum::actingAs($promotor);

        $this->postJson("/api/pontos-venda/{$pdv->uuid}/fachada-promotor", ['imagem' => $this->imagemFake()])->assertUnprocessable();

        $this->assertSame('pontos-venda/x/original.png', $pdv->refresh()->fachada_path);
    }

    public function test_promotor_nao_envia_fachada_de_loja_que_nao_enxerga(): void
    {
        Storage::fake('local');
        $empresa = Empresa::factory()->create();
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        $outroPdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        $outroPdv->promotores()->attach($promotor->id);
        Sanctum::actingAs($promotor);

        // Modo restrito da empresa: o promotor só enxerga as lojas em que está vinculado.
        \App\Models\Parametro::create(['empresa_id' => $empresa->id, 'chave' => 'PONTOS_VENDA_RESTRITO_A_VINCULO', 'valor' => 'true', 'ativo' => true]);

        $this->postJson("/api/pontos-venda/{$pdv->uuid}/fachada-promotor", ['imagem' => $this->imagemFake()])->assertForbidden();
    }

    public function test_admin_nao_usa_a_rota_do_promotor(): void
    {
        Storage::fake('local');
        $empresa = Empresa::factory()->create();
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs(Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]));

        $this->postJson("/api/pontos-venda/{$pdv->uuid}/fachada-promotor", ['imagem' => $this->imagemFake()])->assertForbidden();
    }
}

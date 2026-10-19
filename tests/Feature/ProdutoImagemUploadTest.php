<?php

namespace Tests\Feature;

use App\Models\Empresa;
use App\Models\ProdutoAuditoria;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Upload da foto do produto pelo admin (alternativa a colar URL em imagem_url) — ver
 * ProdutoAuditoriaController::uploadImagem / imagem.
 */
class ProdutoImagemUploadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    // GD não está habilitado neste ambiente — sobe um PNG 1x1 real mínimo, mesmo truque de
    // PlanogramaTest::fotoFake.
    private function fotoFake(): UploadedFile
    {
        $conteudo = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
        );
        $caminho = tempnam(sys_get_temp_dir(), 'produto').'.png';
        file_put_contents($caminho, $conteudo);

        return new UploadedFile($caminho, 'foto.png', 'image/png', null, true);
    }

    /** @return array{0: Empresa, 1: ProdutoAuditoria} */
    private function produtoComAdminLogado(array $atributos = []): array
    {
        $empresa = Empresa::factory()->create();
        Sanctum::actingAs(Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]));
        $produto = ProdutoAuditoria::factory()->create(['empresa_id' => $empresa->id, ...$atributos]);

        return [$empresa, $produto];
    }

    private function caminhoDaUrl(string $url): string
    {
        return 'produtos/'.basename(parse_url($url, PHP_URL_PATH));
    }

    public function test_admin_envia_foto_e_ela_fica_servida_por_url_publica(): void
    {
        [, $produto] = $this->produtoComAdminLogado(['imagem_url' => 'https://cdn.exemplo.com/antiga.png']);

        $response = $this->post("/api/produtos-auditoria/{$produto->uuid}/imagem", ['imagem' => $this->fotoFake()], ['Accept' => 'application/json']);

        $response->assertOk();
        $url = $response->json('produto.imagem_url');
        $this->assertMatchesRegularExpression('#/api/produtos-auditoria/imagens/[0-9a-f-]{36}\.png$#', $url);
        $this->assertSame($url, $produto->fresh()->imagem_url);
        Storage::disk('local')->assertExists($this->caminhoDaUrl($url));

        // Sem autenticação: o app mobile carrega com <Image uri> puro, sem header.
        $this->app['auth']->forgetGuards();
        $this->get(parse_url($url, PHP_URL_PATH))->assertOk()->assertHeader('Content-Type', 'image/png');
    }

    public function test_trocar_foto_apaga_o_arquivo_anterior(): void
    {
        [, $produto] = $this->produtoComAdminLogado();

        $primeira = $this->post("/api/produtos-auditoria/{$produto->uuid}/imagem", ['imagem' => $this->fotoFake()], ['Accept' => 'application/json'])
            ->json('produto.imagem_url');
        $segunda = $this->post("/api/produtos-auditoria/{$produto->uuid}/imagem", ['imagem' => $this->fotoFake()], ['Accept' => 'application/json'])
            ->json('produto.imagem_url');

        $this->assertNotSame($primeira, $segunda);
        Storage::disk('local')->assertMissing($this->caminhoDaUrl($primeira));
        Storage::disk('local')->assertExists($this->caminhoDaUrl($segunda));
    }

    public function test_trocar_por_url_externa_no_put_apaga_o_arquivo_enviado(): void
    {
        [, $produto] = $this->produtoComAdminLogado();
        $enviada = $this->post("/api/produtos-auditoria/{$produto->uuid}/imagem", ['imagem' => $this->fotoFake()], ['Accept' => 'application/json'])
            ->json('produto.imagem_url');

        $this->putJson("/api/produtos-auditoria/{$produto->uuid}", ['imagem_url' => 'https://cdn.exemplo.com/nova.png'])
            ->assertOk()
            ->assertJsonPath('produto.imagem_url', 'https://cdn.exemplo.com/nova.png');

        Storage::disk('local')->assertMissing($this->caminhoDaUrl($enviada));
    }

    public function test_put_sem_mexer_na_imagem_mantem_o_arquivo(): void
    {
        [, $produto] = $this->produtoComAdminLogado();
        $enviada = $this->post("/api/produtos-auditoria/{$produto->uuid}/imagem", ['imagem' => $this->fotoFake()], ['Accept' => 'application/json'])
            ->json('produto.imagem_url');

        $this->putJson("/api/produtos-auditoria/{$produto->uuid}", ['descricao' => 'Outro nome', 'imagem_url' => $enviada])->assertOk();

        Storage::disk('local')->assertExists($this->caminhoDaUrl($enviada));
    }

    public function test_rejeita_arquivo_que_nao_e_imagem(): void
    {
        [, $produto] = $this->produtoComAdminLogado();

        $this->post(
            "/api/produtos-auditoria/{$produto->uuid}/imagem",
            ['imagem' => UploadedFile::fake()->create('planilha.pdf', 10, 'application/pdf')],
            ['Accept' => 'application/json'],
        )->assertUnprocessable()->assertJsonValidationErrors('imagem');
    }

    public function test_rejeita_imagem_acima_de_5mb(): void
    {
        [, $produto] = $this->produtoComAdminLogado();

        $this->post(
            "/api/produtos-auditoria/{$produto->uuid}/imagem",
            ['imagem' => UploadedFile::fake()->create('foto.png', 6000, 'image/png')],
            ['Accept' => 'application/json'],
        )->assertUnprocessable()->assertJsonValidationErrors('imagem');
    }

    public function test_promotor_nao_envia_foto(): void
    {
        $empresa = Empresa::factory()->create();
        $produto = ProdutoAuditoria::factory()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs(Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]));

        $this->post("/api/produtos-auditoria/{$produto->uuid}/imagem", ['imagem' => $this->fotoFake()], ['Accept' => 'application/json'])
            ->assertForbidden();
    }

    public function test_admin_de_outra_empresa_nao_envia_foto(): void
    {
        $produtoDeA = ProdutoAuditoria::factory()->create(['empresa_id' => Empresa::factory()->create()->id]);
        $this->produtoComAdminLogado();

        $this->post("/api/produtos-auditoria/{$produtoDeA->uuid}/imagem", ['imagem' => $this->fotoFake()], ['Accept' => 'application/json'])
            ->assertNotFound();
        $this->assertNull($produtoDeA->fresh()->imagem_url);
    }

    public function test_imagem_inexistente_da_404(): void
    {
        $this->get('/api/produtos-auditoria/imagens/00000000-0000-0000-0000-000000000000.png')->assertNotFound();
    }
}

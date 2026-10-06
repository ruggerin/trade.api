<?php

namespace Tests\Feature;

use App\Enums\TipoDocumentoLegal;
use App\Models\AceiteDocumentoLegal;
use App\Models\DocumentoLegal;
use App\Models\Empresa;
use App\Models\Usuario;
use App\Support\DocumentosLegais;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

/**
 * docs/58-ACEITE-TERMOS-E-PRIVACIDADE.md — versões publicadas dos Termos/Política, pendência no
 * login/me, aceite append-only apontando pra versão exata.
 */
class Doc58DocumentosLegaisTest extends TestCase
{
    use RefreshDatabase;

    private Empresa $empresa;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-06 12:00:00', 'UTC'));
        $this->empresa = Empresa::factory()->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function texto(string $data, string $corpo = 'Texto.'): string
    {
        return "# Documento\n\n**Última atualização:** {$data}\n\n{$corpo}\n";
    }

    private function publicarAmbos(string $data = '06 de outubro de 2026'): void
    {
        DocumentosLegais::publicar(TipoDocumentoLegal::TERMOS_USO, $this->texto($data, 'Termos.'));
        DocumentosLegais::publicar(TipoDocumentoLegal::POLITICA_PRIVACIDADE, $this->texto($data, 'Política.'));
    }

    private function corpoAceite(string $versao = '2026-10-06'): array
    {
        return ['documentos' => [
            ['tipo' => 'TERMOS_USO', 'versao' => $versao],
            ['tipo' => 'POLITICA_PRIVACIDADE', 'versao' => $versao],
        ]];
    }

    // ——— Publicação ———

    public function test_versao_vem_da_data_de_ultima_atualizacao(): void
    {
        $this->assertSame('2026-10-06', DocumentosLegais::versaoDoTexto($this->texto('06 de outubro de 2026')));
        $this->assertSame('2027-03-01', DocumentosLegais::versaoDoTexto($this->texto('1 de março de 2027')));
    }

    public function test_publica_versao_nova_e_nao_duplica_com_mesmo_texto(): void
    {
        $texto = $this->texto('06 de outubro de 2026');

        $this->assertSame('publicado', DocumentosLegais::publicar(TipoDocumentoLegal::TERMOS_USO, $texto)['status']);
        $this->assertSame('sem_mudanca', DocumentosLegais::publicar(TipoDocumentoLegal::TERMOS_USO, $texto)['status']);

        $documento = DocumentoLegal::sole();
        $this->assertSame('2026-10-06', $documento->versao);
        $this->assertSame(hash('sha256', $texto), $documento->hash_sha256);
    }

    public function test_falha_quando_o_texto_muda_sem_mudar_a_data(): void
    {
        DocumentosLegais::publicar(TipoDocumentoLegal::TERMOS_USO, $this->texto('06 de outubro de 2026', 'Original.'));

        $this->expectException(RuntimeException::class);
        DocumentosLegais::publicar(TipoDocumentoLegal::TERMOS_USO, $this->texto('06 de outubro de 2026', 'Alterado.'));
    }

    public function test_comando_publica_os_arquivos_e_dry_run_nao_grava(): void
    {
        $this->artisan('documentos-legais:publicar', ['--dry-run' => true])->assertSuccessful();
        $this->assertSame(0, DocumentoLegal::count());

        $this->artisan('documentos-legais:publicar')->assertSuccessful();
        $this->assertSame(2, DocumentoLegal::count());

        $this->artisan('documentos-legais:publicar')->assertSuccessful();
        $this->assertSame(2, DocumentoLegal::count());
    }

    // ——— Pendência no login/me ———

    public function test_login_e_me_listam_as_versoes_pendentes(): void
    {
        $this->publicarAmbos();
        Usuario::factory()->admin()->create([
            'empresa_id' => $this->empresa->id,
            'email' => 'admin@teste.com',
            'senha_hash' => Hash::make('senha12345'),
        ]);

        $this->postJson('/api/auth/login', ['email' => 'admin@teste.com', 'senha' => 'senha12345'])
            ->assertOk()
            ->assertJsonPath('usuario.documentos_pendentes', [
                ['tipo' => 'TERMOS_USO', 'versao' => '2026-10-06'],
                ['tipo' => 'POLITICA_PRIVACIDADE', 'versao' => '2026-10-06'],
            ]);
    }

    public function test_sem_documento_publicado_nao_ha_pendencia(): void
    {
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $this->empresa->id]);
        Sanctum::actingAs($admin);

        $this->getJson('/api/auth/me')->assertOk()->assertJsonPath('usuario.documentos_pendentes', []);
    }

    public function test_superadmin_nunca_tem_pendencia(): void
    {
        $this->publicarAmbos();
        Sanctum::actingAs(Usuario::factory()->superadmin()->create());

        $this->getJson('/api/auth/me')->assertOk()->assertJsonPath('usuario.documentos_pendentes', []);
    }

    public function test_versao_nova_volta_a_ficar_pendente(): void
    {
        $this->publicarAmbos();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $this->empresa->id]);
        Sanctum::actingAs($admin);
        $this->postJson('/api/auth/me/aceites', $this->corpoAceite())->assertOk();

        Carbon::setTestNow(now()->addMonth());
        DocumentosLegais::publicar(TipoDocumentoLegal::TERMOS_USO, $this->texto('06 de novembro de 2026', 'Termos v2.'));

        $this->getJson('/api/auth/me')->assertOk()->assertJsonPath('usuario.documentos_pendentes', [
            ['tipo' => 'TERMOS_USO', 'versao' => '2026-11-06'],
        ]);
    }

    // ——— Aceite ———

    public function test_aceite_grava_uma_linha_por_documento_com_ip_e_app(): void
    {
        $this->publicarAmbos();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $this->empresa->id]);
        Sanctum::actingAs($admin);

        $this->postJson('/api/auth/me/aceites', $this->corpoAceite(), ['X-Client' => 'admin'])
            ->assertOk()
            ->assertJsonPath('usuario.documentos_pendentes', []);

        $aceites = AceiteDocumentoLegal::where('usuario_id', $admin->id)->get();
        $this->assertCount(2, $aceites);
        $this->assertSame(['ADMIN'], $aceites->pluck('app')->unique()->values()->all());
        $this->assertSame('127.0.0.1', $aceites->first()->ip);
    }

    public function test_aceite_e_idempotente(): void
    {
        $this->publicarAmbos();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $this->empresa->id]);
        Sanctum::actingAs($admin);

        $this->postJson('/api/auth/me/aceites', $this->corpoAceite())->assertOk();
        $primeiro = AceiteDocumentoLegal::orderBy('id')->first()->aceito_em;

        Carbon::setTestNow(now()->addHour());
        $this->postJson('/api/auth/me/aceites', $this->corpoAceite())->assertOk();

        $this->assertSame(2, AceiteDocumentoLegal::count());
        $this->assertTrue($primeiro->equalTo(AceiteDocumentoLegal::orderBy('id')->first()->aceito_em));
    }

    public function test_aceite_de_versao_desatualizada_retorna_409_sem_gravar(): void
    {
        $this->publicarAmbos();
        Sanctum::actingAs(Usuario::factory()->admin()->create(['empresa_id' => $this->empresa->id]));

        $this->postJson('/api/auth/me/aceites', $this->corpoAceite('2026-01-01'))
            ->assertStatus(409)
            ->assertJsonCount(2, 'documentos_pendentes');

        $this->assertSame(0, AceiteDocumentoLegal::count());
    }

    public function test_aceite_com_tipo_invalido_retorna_422(): void
    {
        $this->publicarAmbos();
        Sanctum::actingAs(Usuario::factory()->admin()->create(['empresa_id' => $this->empresa->id]));

        $this->postJson('/api/auth/me/aceites', ['documentos' => [['tipo' => 'CONTRATO', 'versao' => '2026-10-06']]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('documentos.0.tipo');
    }

    public function test_signup_com_documentos_ja_grava_o_aceite(): void
    {
        $this->publicarAmbos();

        $this->postJson('/api/empresas/signup', [
            'razao_social' => 'Distribuidora Teste Ltda',
            'nome_fantasia' => 'Distribuidora Teste',
            'cnpj' => '12345678000199',
            'admin_nome' => 'Admin Novo',
            'admin_email' => 'novo@teste.com',
            'admin_senha' => 'senha12345',
            ...$this->corpoAceite(),
        ])->assertCreated()->assertJsonPath('usuario.documentos_pendentes', []);

        $this->assertSame(2, AceiteDocumentoLegal::count());
    }

    // ——— Leitura pública ———

    public function test_leitura_publica_sem_token(): void
    {
        $this->publicarAmbos();

        $this->getJson('/api/documentos-legais/termos-de-uso')
            ->assertOk()
            ->assertJsonPath('tipo', 'TERMOS_USO')
            ->assertJsonPath('versao', '2026-10-06');

        $this->getJson('/api/documentos-legais/politica-de-privacidade?versao=2026-10-06')->assertOk();
        $this->getJson('/api/documentos-legais/termos-de-uso?versao=2020-01-01')->assertNotFound();

        $this->get('/politica-de-privacidade')->assertOk()->assertSee('Política.', false);
    }

    // ——— Histórico ———

    public function test_historico_de_aceites_do_usuario_e_restrito_a_empresa(): void
    {
        $this->publicarAmbos();
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $this->empresa->id]);
        Sanctum::actingAs($promotor);
        $this->postJson('/api/auth/me/aceites', $this->corpoAceite(), ['X-Client' => 'mobile'])->assertOk();

        Sanctum::actingAs(Usuario::factory()->admin()->create(['empresa_id' => $this->empresa->id]));
        $this->getJson("/api/usuarios/{$promotor->uuid}/aceites")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.app', 'MOBILE');

        $outraEmpresa = Empresa::factory()->create();
        Sanctum::actingAs(Usuario::factory()->admin()->create(['empresa_id' => $outraEmpresa->id]));
        $this->getJson("/api/usuarios/{$promotor->uuid}/aceites")->assertNotFound();
    }

    public function test_excluir_o_usuario_nao_apaga_os_aceites(): void
    {
        $this->publicarAmbos();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $this->empresa->id]);
        Sanctum::actingAs($admin);
        $this->postJson('/api/auth/me/aceites', $this->corpoAceite())->assertOk();

        $this->expectException(QueryException::class);
        Usuario::withoutGlobalScopes()->whereKey($admin->id)->delete();
    }
}

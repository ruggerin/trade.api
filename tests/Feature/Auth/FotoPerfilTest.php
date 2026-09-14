<?php

namespace Tests\Feature\Auth;

use App\Models\Empresa;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Foto de perfil, self-service — qualquer usuário autenticado troca a própria, sem exigir
 * `usuarios.gerenciar` (essa permissão é pra mexer no cadastro de outra pessoa).
 * `foto_path`/`foto_url` são separados de `avatar_url` de propósito: `avatar_url` continua
 * sendo o texto livre que o admin web já preenche manualmente.
 */
class FotoPerfilTest extends TestCase
{
    use RefreshDatabase;

    // GD não está habilitado neste ambiente (UploadedFile::fake()->image() precisa dela) — sobe
    // um PNG 1x1 real e mínimo, só com bytes válidos o bastante pra passar na regra `image`.
    private function imagemFake(string $nome = 'foto.png'): UploadedFile
    {
        $conteudo = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
        );
        $caminho = tempnam(sys_get_temp_dir(), 'foto').'.png';
        file_put_contents($caminho, $conteudo);

        return new UploadedFile($caminho, $nome, 'image/png', null, true);
    }

    public function test_usuario_envia_a_propria_foto(): void
    {
        Storage::fake('local');
        $empresa = Empresa::factory()->create();
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($promotor);

        $response = $this->postJson('/api/auth/me/foto', ['imagem' => $this->imagemFake()]);

        $response->assertOk();
        $this->assertNotNull($response->json('usuario.foto_url'));
        $promotor->refresh();
        $this->assertNotNull($promotor->foto_path);
        Storage::disk('local')->assertExists($promotor->foto_path);
    }

    public function test_enviar_nova_foto_substitui_a_anterior_sem_deixar_lixo(): void
    {
        Storage::fake('local');
        $empresa = Empresa::factory()->create();
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($promotor);

        $this->postJson('/api/auth/me/foto', ['imagem' => $this->imagemFake('primeira.png')])->assertOk();
        $promotor->refresh();
        $caminhoAntigo = $promotor->foto_path;

        $this->postJson('/api/auth/me/foto', ['imagem' => $this->imagemFake('segunda.png')])->assertOk();
        $promotor->refresh();

        $this->assertNotSame($caminhoAntigo, $promotor->foto_path);
        Storage::disk('local')->assertMissing($caminhoAntigo);
        Storage::disk('local')->assertExists($promotor->foto_path);
    }

    public function test_sem_arquivo_retorna_422(): void
    {
        $empresa = Empresa::factory()->create();
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($promotor);

        $this->postJson('/api/auth/me/foto', [])->assertStatus(422)->assertJsonValidationErrors('imagem');
    }

    public function test_usuario_remove_a_propria_foto(): void
    {
        Storage::fake('local');
        $empresa = Empresa::factory()->create();
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($promotor);
        $this->postJson('/api/auth/me/foto', ['imagem' => $this->imagemFake()])->assertOk();
        $promotor->refresh();
        $caminho = $promotor->foto_path;

        $response = $this->deleteJson('/api/auth/me/foto');

        $response->assertOk()->assertJsonPath('usuario.foto_url', null);
        Storage::disk('local')->assertMissing($caminho);
        $this->assertNull($promotor->refresh()->foto_path);
    }

    public function test_sem_foto_enviada_auth_me_retorna_foto_url_nula(): void
    {
        $empresa = Empresa::factory()->create();
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($promotor);

        $this->getJson('/api/auth/me')->assertOk()->assertJsonPath('usuario.foto_url', null);
    }

    public function test_qualquer_autenticado_da_mesma_empresa_ve_a_foto_de_um_colega(): void
    {
        Storage::fake('local');
        $empresa = Empresa::factory()->create();
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        $colega = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($colega);
        $this->postJson('/api/auth/me/foto', ['imagem' => $this->imagemFake()])->assertOk();

        Sanctum::actingAs($promotor);
        $this->getJson("/api/usuarios/{$colega->uuid}/foto")->assertOk();
    }

    public function test_foto_de_usuario_sem_foto_retorna_404(): void
    {
        $empresa = Empresa::factory()->create();
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        $colega = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($promotor);

        $this->getJson("/api/usuarios/{$colega->uuid}/foto")->assertNotFound();
    }
}

<?php

namespace Tests\Feature;

use App\Models\Dispositivo;
use App\Models\Empresa;
use App\Models\Perfil;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * docs/02-API-BACKEND.md#usuários — GET /usuarios/{uuid} (detalhe) e
 * DELETE /usuarios/{uuid}/dispositivo (revogar sessão do aparelho, sem esperar o promotor
 * logar de novo pra liberar a licença).
 */
class UsuarioGerenciamentoTest extends TestCase
{
    use RefreshDatabase;

    public function test_show_retorna_usuario_com_perfil_e_dispositivo(): void
    {
        $empresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        Dispositivo::create([
            'usuario_id' => $promotor->id,
            'identificador' => 'aparelho-123',
            'nome' => 'Moto G',
            'ultimo_acesso_em' => now(),
        ]);
        Sanctum::actingAs($admin);

        $response = $this->getJson("/api/usuarios/{$promotor->uuid}");

        $response->assertOk()
            ->assertJsonPath('usuario.id', $promotor->uuid)
            ->assertJsonPath('usuario.dispositivo.identificador', 'aparelho-123');
    }

    public function test_admin_atribui_perfil_a_promotor(): void
    {
        // docs/12-VISIBILIDADE-PONTOS-DE-VENDA.md — Perfil deixou de ser exclusivo de GESTOR.
        $empresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        $perfil = Perfil::factory()->comPermissoes(['pontos_venda.visualizar_todos'])->create(['empresa_id' => $empresa->id]);
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($admin);

        $response = $this->putJson("/api/usuarios/{$promotor->uuid}", ['perfil_uuid' => $perfil->uuid]);

        $response->assertOk()->assertJsonPath('usuario.perfil.id', $perfil->uuid);
    }

    public function test_perfil_rejeitado_para_admin(): void
    {
        $empresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        $perfil = Perfil::factory()->create(['empresa_id' => $empresa->id]);
        $outroAdmin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($admin);

        $this->putJson("/api/usuarios/{$outroAdmin->uuid}", ['perfil_uuid' => $perfil->uuid])
            ->assertStatus(422)
            ->assertJsonValidationErrors('perfil_uuid');
    }

    public function test_admin_revoga_dispositivo_do_promotor_e_derruba_a_sessao(): void
    {
        $empresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        Dispositivo::create([
            'usuario_id' => $promotor->id,
            'identificador' => 'aparelho-123',
            'ultimo_acesso_em' => now(),
        ]);
        $promotor->createToken('acesso-api');
        Sanctum::actingAs($admin);

        $response = $this->deleteJson("/api/usuarios/{$promotor->uuid}/dispositivo");

        $response->assertNoContent();
        $this->assertDatabaseMissing('dispositivos', ['usuario_id' => $promotor->id]);
        $this->assertSame(0, $promotor->tokens()->count());
    }

    public function test_revogar_dispositivo_sem_nenhum_vinculado_retorna_422(): void
    {
        $empresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($admin);

        $this->deleteJson("/api/usuarios/{$promotor->uuid}/dispositivo")->assertStatus(422);
    }

    public function test_promotor_nao_acessa_rotas_de_gerenciamento_de_usuarios(): void
    {
        $empresa = Empresa::factory()->create();
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        $outroPromotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($promotor);

        $this->getJson("/api/usuarios/{$outroPromotor->uuid}")->assertForbidden();
        $this->deleteJson("/api/usuarios/{$outroPromotor->uuid}/dispositivo")->assertForbidden();
    }

    public function test_superadmin_nao_revoga_dispositivo_delete_continua_bloqueado(): void
    {
        $empresa = Empresa::factory()->create();
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        Dispositivo::create([
            'usuario_id' => $promotor->id,
            'identificador' => 'aparelho-123',
            'ultimo_acesso_em' => now(),
        ]);
        $superadmin = Usuario::factory()->superadmin()->create();
        Sanctum::actingAs($superadmin);

        $this->deleteJson("/api/usuarios/{$promotor->uuid}/dispositivo")->assertForbidden();
    }

    public function test_admin_vincula_centro_de_custo_a_promotor(): void
    {
        $empresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        $centroCusto = \App\Models\CentroCusto::factory()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($admin);

        $response = $this->putJson("/api/usuarios/{$promotor->uuid}", [
            'centro_custo_uuid' => $centroCusto->uuid,
        ]);

        $response->assertOk()->assertJsonPath('usuario.centro_custo.id', $centroCusto->uuid);
        $this->assertDatabaseHas('usuarios', ['id' => $promotor->id, 'centro_custo_id' => $centroCusto->id]);
    }

    public function test_centro_custo_rejeitado_para_usuario_que_nao_e_promotor(): void
    {
        $empresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        $gestor = Usuario::factory()->gestor()->create(['empresa_id' => $empresa->id]);
        $centroCusto = \App\Models\CentroCusto::factory()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($admin);

        $this->putJson("/api/usuarios/{$gestor->uuid}", ['centro_custo_uuid' => $centroCusto->uuid])
            ->assertStatus(422)
            ->assertJsonValidationErrors('centro_custo_uuid');
    }

    public function test_centro_custo_de_outra_empresa_e_rejeitado(): void
    {
        $empresa = Empresa::factory()->create();
        $outraEmpresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        $centroCustoDeOutraEmpresa = \App\Models\CentroCusto::factory()->create(['empresa_id' => $outraEmpresa->id]);
        Sanctum::actingAs($admin);

        $this->putJson("/api/usuarios/{$promotor->uuid}", ['centro_custo_uuid' => $centroCustoDeOutraEmpresa->uuid])
            ->assertStatus(422)
            ->assertJsonValidationErrors('centro_custo_uuid');
    }

    // GD não está habilitado neste ambiente (UploadedFile::fake()->image() precisa dela) — sobe
    // um PNG 1x1 real e mínimo, mesma técnica de Tests\Feature\Auth\FotoPerfilTest.
    private function imagemFake(string $nome = 'foto.png'): UploadedFile
    {
        $conteudo = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
        );
        $caminho = tempnam(sys_get_temp_dir(), 'foto').'.png';
        file_put_contents($caminho, $conteudo);

        return new UploadedFile($caminho, $nome, 'image/png', null, true);
    }

    public function test_admin_envia_a_foto_de_outro_usuario(): void
    {
        Storage::fake('local');
        $empresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($admin);

        $response = $this->postJson("/api/usuarios/{$promotor->uuid}/foto", ['imagem' => $this->imagemFake()]);

        $response->assertOk();
        $this->assertNotNull($response->json('usuario.foto_url'));
        $promotor->refresh();
        $this->assertNotNull($promotor->foto_path);
        Storage::disk('local')->assertExists($promotor->foto_path);
    }

    public function test_enviar_nova_foto_de_outro_usuario_substitui_a_anterior_sem_deixar_lixo(): void
    {
        Storage::fake('local');
        $empresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($admin);

        $this->postJson("/api/usuarios/{$promotor->uuid}/foto", ['imagem' => $this->imagemFake('primeira.png')])->assertOk();
        $caminhoAntigo = $promotor->refresh()->foto_path;

        $this->postJson("/api/usuarios/{$promotor->uuid}/foto", ['imagem' => $this->imagemFake('segunda.png')])->assertOk();
        $promotor->refresh();

        $this->assertNotSame($caminhoAntigo, $promotor->foto_path);
        Storage::disk('local')->assertMissing($caminhoAntigo);
        Storage::disk('local')->assertExists($promotor->foto_path);
    }

    public function test_admin_remove_a_foto_de_outro_usuario(): void
    {
        Storage::fake('local');
        $empresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($admin);
        $this->postJson("/api/usuarios/{$promotor->uuid}/foto", ['imagem' => $this->imagemFake()])->assertOk();
        $caminho = $promotor->refresh()->foto_path;

        $response = $this->deleteJson("/api/usuarios/{$promotor->uuid}/foto");

        $response->assertOk()->assertJsonPath('usuario.foto_url', null);
        Storage::disk('local')->assertMissing($caminho);
        $this->assertNull($promotor->refresh()->foto_path);
    }

    public function test_promotor_nao_envia_foto_de_outro_usuario(): void
    {
        $empresa = Empresa::factory()->create();
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        $colega = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($promotor);

        $this->postJson("/api/usuarios/{$colega->uuid}/foto", ['imagem' => $this->imagemFake()])->assertForbidden();
        $this->deleteJson("/api/usuarios/{$colega->uuid}/foto")->assertForbidden();
    }
}

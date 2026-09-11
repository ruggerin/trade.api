<?php

namespace Tests\Feature\PontoVenda;

use App\Models\Empresa;
use App\Models\OrdemServico;
use App\Models\Parametro;
use App\Models\Perfil;
use App\Models\PontoVenda;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * docs/02-API-BACKEND.md, regra de negócio 6 (modo aberto, default) e
 * docs/12-VISIBILIDADE-PONTOS-DE-VENDA.md (perfil "ver tudo", modo restrito por empresa,
 * vínculo temporário via OrdemServico) — atribuição/visibilidade de pontos de venda por
 * promotor.
 */
class PromotorAtribuicaoTest extends TestCase
{
    use RefreshDatabase;

    public function test_promotor_ve_lojas_atribuidas_a_ele_e_lojas_sem_ninguem_atribuido(): void
    {
        $empresa = Empresa::factory()->create();
        $promotorA = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        $promotorB = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        $pdvDoA = PontoVenda::factory()->create(['empresa_id' => $empresa->id, 'fantasia' => 'Loja do A']);
        $pdvSemNinguem = PontoVenda::factory()->create(['empresa_id' => $empresa->id, 'fantasia' => 'Loja sem dono']);
        $pdvDoB = PontoVenda::factory()->create(['empresa_id' => $empresa->id, 'fantasia' => 'Loja do B']);

        $pdvDoA->promotores()->attach($promotorA->id);
        $pdvDoB->promotores()->attach($promotorB->id);

        Sanctum::actingAs($promotorA);
        $response = $this->getJson('/api/pontos-venda')->assertOk();

        $fantasias = collect($response->json('pontos_venda'))->pluck('fantasia');
        $this->assertContains('Loja do A', $fantasias);
        $this->assertContains('Loja sem dono', $fantasias);
        $this->assertNotContains('Loja do B', $fantasias);
    }

    public function test_promotor_com_perfil_visualizar_todos_ve_tudo_mesmo_sem_vinculo(): void
    {
        $empresa = Empresa::factory()->create();
        $perfil = Perfil::factory()->comPermissoes(['pontos_venda.visualizar_todos'])->create(['empresa_id' => $empresa->id]);
        $supervisor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id, 'perfil_id' => $perfil->id]);
        $outroPromotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        $pdvDeOutraPessoa = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        $pdvDeOutraPessoa->promotores()->attach($outroPromotor->id);

        Sanctum::actingAs($supervisor);
        $response = $this->getJson('/api/pontos-venda')->assertOk();

        $this->assertCount(1, $response->json('pontos_venda'));
    }

    public function test_modo_restrito_esconde_pdv_sem_vinculo_e_sem_os(): void
    {
        $empresa = Empresa::factory()->create();
        Parametro::create(['empresa_id' => $empresa->id, 'chave' => 'PONTOS_VENDA_RESTRITO_A_VINCULO', 'valor' => 'true']);
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        PontoVenda::factory()->create(['empresa_id' => $empresa->id]); // sem ninguém vinculado

        Sanctum::actingAs($promotor);
        $response = $this->getJson('/api/pontos-venda')->assertOk();

        $this->assertCount(0, $response->json('pontos_venda'));
    }

    public function test_modo_restrito_libera_pdv_com_os_ativa_direcionada_ao_promotor(): void
    {
        $empresa = Empresa::factory()->create();
        Parametro::create(['empresa_id' => $empresa->id, 'chave' => 'PONTOS_VENDA_RESTRITO_A_VINCULO', 'valor' => 'true']);
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id, 'fantasia' => 'Cobertura extraordinária']);
        OrdemServico::factory()->create([
            'empresa_id' => $empresa->id, 'ponto_venda_id' => $pdv->id, 'usuario_id' => $promotor->id,
        ]);

        Sanctum::actingAs($promotor);
        $response = $this->getJson('/api/pontos-venda')->assertOk();

        $fantasias = collect($response->json('pontos_venda'))->pluck('fantasia');
        $this->assertContains('Cobertura extraordinária', $fantasias);
    }

    public function test_modo_restrito_nao_libera_pdv_com_os_de_outro_promotor_ou_ja_concluida(): void
    {
        $empresa = Empresa::factory()->create();
        Parametro::create(['empresa_id' => $empresa->id, 'chave' => 'PONTOS_VENDA_RESTRITO_A_VINCULO', 'valor' => 'true']);
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        $outroPromotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        $pdvDeOutroPromotor = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        $pdvComOsConcluida = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        OrdemServico::factory()->create([
            'empresa_id' => $empresa->id, 'ponto_venda_id' => $pdvDeOutroPromotor->id, 'usuario_id' => $outroPromotor->id,
        ]);
        OrdemServico::factory()->concluida()->create([
            'empresa_id' => $empresa->id, 'ponto_venda_id' => $pdvComOsConcluida->id, 'usuario_id' => $promotor->id,
        ]);

        Sanctum::actingAs($promotor);
        $response = $this->getJson('/api/pontos-venda')->assertOk();

        $this->assertCount(0, $response->json('pontos_venda'));
    }

    public function test_admin_e_gestor_veem_todas_as_lojas_independente_de_atribuicao(): void
    {
        $empresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        $pdvAtribuido = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        $pdvOutraLoja = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        $pdvAtribuido->promotores()->attach($promotor->id);

        Sanctum::actingAs($admin);
        $response = $this->getJson('/api/pontos-venda')->assertOk();

        $this->assertCount(2, $response->json('pontos_venda'));
    }

    public function test_admin_sincroniza_promotores_de_uma_loja(): void
    {
        $empresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        $promotorA = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        $promotorB = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);

        Sanctum::actingAs($admin);

        // Atribui os dois.
        $response = $this->putJson("/api/pontos-venda/{$pdv->uuid}/promotores", [
            'usuarios_uuids' => [$promotorA->uuid, $promotorB->uuid],
        ]);
        $response->assertOk();
        $nomes = collect($response->json('ponto_venda.promotores'))->pluck('id');
        $this->assertCount(2, $nomes);

        // Sync substitui — manda só um, o outro é desvinculado.
        $response = $this->putJson("/api/pontos-venda/{$pdv->uuid}/promotores", [
            'usuarios_uuids' => [$promotorA->uuid],
        ]);
        $response->assertOk();
        $this->assertEquals([$promotorA->uuid], collect($response->json('ponto_venda.promotores'))->pluck('id')->all());

        $this->assertDatabaseCount('promotor_pontos_venda', 1);
    }

    public function test_sync_rejeita_usuario_que_nao_e_promotor_da_mesma_empresa(): void
    {
        $empresa = Empresa::factory()->create();
        $outraEmpresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        $gestor = Usuario::factory()->gestor()->create(['empresa_id' => $empresa->id]);
        $promotorDeOutraEmpresa = Usuario::factory()->promotor()->create(['empresa_id' => $outraEmpresa->id]);
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);

        Sanctum::actingAs($admin);

        // GESTOR da própria empresa não é PROMOTOR — não pode ser atribuído a uma loja.
        $this->putJson("/api/pontos-venda/{$pdv->uuid}/promotores", [
            'usuarios_uuids' => [$gestor->uuid],
        ])->assertStatus(422);

        // PROMOTOR existe, mas é de outra empresa.
        $this->putJson("/api/pontos-venda/{$pdv->uuid}/promotores", [
            'usuarios_uuids' => [$promotorDeOutraEmpresa->uuid],
        ])->assertStatus(422);

        $this->assertDatabaseCount('promotor_pontos_venda', 0);
    }

    public function test_promotor_nao_pode_chamar_endpoint_de_sincronizar_promotores(): void
    {
        $empresa = Empresa::factory()->create();
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);

        Sanctum::actingAs($promotor);

        $this->putJson("/api/pontos-venda/{$pdv->uuid}/promotores", [
            'usuarios_uuids' => [$promotor->uuid],
        ])->assertForbidden();
    }

    // ---- Atribuir/remover um de cada vez (evita a race de recalcular a lista no cliente) ----

    public function test_admin_atribui_um_promotor_sem_mexer_nos_demais(): void
    {
        $empresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        $promotorA = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        $promotorB = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        $pdv->promotores()->attach($promotorA->id);
        Sanctum::actingAs($admin);

        $response = $this->postJson("/api/pontos-venda/{$pdv->uuid}/promotores/{$promotorB->uuid}");

        $response->assertOk();
        $this->assertEqualsCanonicalizing(
            [$promotorA->uuid, $promotorB->uuid],
            collect($response->json('ponto_venda.promotores'))->pluck('id')->all(),
        );
    }

    public function test_atribuir_o_mesmo_promotor_duas_vezes_nao_duplica(): void
    {
        $empresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($admin);

        $this->postJson("/api/pontos-venda/{$pdv->uuid}/promotores/{$promotor->uuid}")->assertOk();
        $this->postJson("/api/pontos-venda/{$pdv->uuid}/promotores/{$promotor->uuid}")->assertOk();

        $this->assertDatabaseCount('promotor_pontos_venda', 1);
    }

    public function test_admin_remove_um_promotor_sem_mexer_nos_demais(): void
    {
        $empresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        $promotorA = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        $promotorB = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        $pdv->promotores()->attach([$promotorA->id, $promotorB->id]);
        Sanctum::actingAs($admin);

        $response = $this->deleteJson("/api/pontos-venda/{$pdv->uuid}/promotores/{$promotorA->uuid}");

        $response->assertOk();
        $this->assertEquals([$promotorB->uuid], collect($response->json('ponto_venda.promotores'))->pluck('id')->all());
    }

    public function test_atribuir_rejeita_gestor_de_outra_empresa_ou_usuario_nao_promotor(): void
    {
        $empresa = Empresa::factory()->create();
        $outraEmpresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        $gestor = Usuario::factory()->gestor()->create(['empresa_id' => $empresa->id]);
        $promotorDeOutraEmpresa = Usuario::factory()->promotor()->create(['empresa_id' => $outraEmpresa->id]);
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($admin);

        // GESTOR da própria empresa não é PROMOTOR.
        $this->postJson("/api/pontos-venda/{$pdv->uuid}/promotores/{$gestor->uuid}")->assertStatus(422);
        // PROMOTOR existe, mas é de outra empresa — o route binding nem acha (404).
        $this->postJson("/api/pontos-venda/{$pdv->uuid}/promotores/{$promotorDeOutraEmpresa->uuid}")->assertStatus(404);

        $this->assertDatabaseCount('promotor_pontos_venda', 0);
    }

    public function test_promotor_nao_pode_atribuir_ou_remover_a_si_mesmo(): void
    {
        $empresa = Empresa::factory()->create();
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($promotor);

        $this->postJson("/api/pontos-venda/{$pdv->uuid}/promotores/{$promotor->uuid}")->assertForbidden();
        $this->deleteJson("/api/pontos-venda/{$pdv->uuid}/promotores/{$promotor->uuid}")->assertForbidden();
    }

    public function test_admin_filtra_listagem_por_promotor(): void
    {
        $empresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        $promotorA = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        $promotorB = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        $pdvDoA = PontoVenda::factory()->create(['empresa_id' => $empresa->id, 'fantasia' => 'Loja do A']);
        $pdvDoB = PontoVenda::factory()->create(['empresa_id' => $empresa->id, 'fantasia' => 'Loja do B']);
        $pdvDoA->promotores()->attach($promotorA->id);
        $pdvDoB->promotores()->attach($promotorB->id);

        Sanctum::actingAs($admin);
        $response = $this->getJson("/api/pontos-venda?promotor_uuid={$promotorA->uuid}")->assertOk();

        $fantasias = collect($response->json('pontos_venda'))->pluck('fantasia');
        $this->assertEquals(['Loja do A'], $fantasias->all());
    }
}

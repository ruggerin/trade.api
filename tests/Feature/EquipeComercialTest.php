<?php

namespace Tests\Feature;

use App\Enums\Permissao;
use App\Models\Empresa;
use App\Models\Perfil;
use App\Models\PontoVenda;
use App\Models\Supervisor;
use App\Models\Usuario;
use App\Models\Vendedor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * docs/73 Fase 1 — cadastro de vendedores e supervisores espelhado do ERP (§10 Verificação).
 */
class EquipeComercialTest extends TestCase
{
    use RefreshDatabase;

    private Empresa $empresa;

    protected function setUp(): void
    {
        parent::setUp();
        $this->empresa = Empresa::factory()->create();
        Sanctum::actingAs(Usuario::factory()->admin()->create(['empresa_id' => $this->empresa->id]));
    }

    private function csv(string $url, array $linhas, bool $simular = false)
    {
        $arquivo = UploadedFile::fake()->createWithContent('equipe.csv', implode("\r\n", $linhas));

        return $this->post($url, ['arquivo' => $arquivo, 'simular' => $simular ? '1' : '0'], ['Accept' => 'application/json']);
    }

    public function test_integrador_sincroniza_e_reenvio_do_mesmo_lote_nao_duplica(): void
    {
        $lote = ['supervisores' => [
            ['codigo_externo' => 'S1', 'nome' => 'Ana Supervisora', 'email' => 'ana@exemplo.com'],
            ['codigo_externo' => 'S2', 'nome' => 'Bruno Supervisor'],
        ]];

        $this->postJson('/api/supervisores/sincronizar', $lote)->assertOk()
            ->assertJsonPath('criadas', 2)->assertJsonPath('aplicado', true);
        $this->postJson('/api/supervisores/sincronizar', $lote)->assertOk()
            ->assertJsonPath('criadas', 0)->assertJsonPath('atualizadas', 2);

        $this->assertSame(2, Supervisor::count());
    }

    public function test_vendedor_resolve_supervisor_pelo_codsupervisor(): void
    {
        $this->postJson('/api/supervisores/sincronizar', ['supervisores' => [['codigo_externo' => 'S1', 'nome' => 'Ana']]]);

        $this->postJson('/api/vendedores/sincronizar', ['vendedores' => [
            ['codigo_externo' => 'V1', 'nome' => 'Carlos RCA', 'telefone' => '(92) 99999-0000', 'codigo_supervisor' => 'S1'],
        ]])->assertOk()->assertJsonPath('avisos', []);

        $vendedor = Vendedor::where('codigo_externo', 'V1')->firstOrFail();
        $this->assertSame(Supervisor::where('codigo_externo', 'S1')->value('id'), $vendedor->supervisor_id);
        $this->assertSame('(92) 99999-0000', $vendedor->telefone);
    }

    public function test_supervisor_inexistente_vira_aviso_e_vincula_quando_chega(): void
    {
        $this->postJson('/api/vendedores/sincronizar', ['vendedores' => [
            ['codigo_externo' => 'V1', 'nome' => 'Carlos', 'codigo_supervisor' => 'S9'],
            ['codigo_externo' => 'V2', 'nome' => 'Dora', 'codigo_supervisor' => 'S9'],
        ]])->assertOk()
            ->assertJsonPath('criadas', 2)
            ->assertJsonPath('aplicado', true)
            ->assertJsonCount(2, 'avisos');

        $this->assertNull(Vendedor::where('codigo_externo', 'V1')->value('supervisor_id'));

        $this->postJson('/api/supervisores/sincronizar', ['supervisores' => [['codigo_externo' => 'S9', 'nome' => 'Eva']]])->assertOk();

        $id = Supervisor::where('codigo_externo', 'S9')->value('id');
        $this->assertSame(2, Vendedor::where('supervisor_id', $id)->count());
    }

    public function test_integrador_grava_linhas_validas_e_devolve_as_recusadas(): void
    {
        $this->postJson('/api/vendedores/sincronizar', ['vendedores' => [
            ['codigo_externo' => 'V1', 'nome' => 'Válido'],
            ['codigo_externo' => 'V2', 'nome' => 'E-mail ruim', 'email' => 'nao-e-email'],
            ['nome' => 'Sem código'],
            ['codigo_externo' => 'V3'],
        ]])->assertOk()
            ->assertJsonPath('criadas', 1)
            ->assertJsonPath('aplicado', true)
            ->assertJsonCount(3, 'erros')
            ->assertJsonPath('erros.0.linha', 2)
            ->assertJsonPath('erros.0.codigo_externo', 'V2');

        $this->assertSame(['V1'], Vendedor::pluck('codigo_externo')->all());
    }

    public function test_atualizacao_so_mexe_no_que_veio_e_inativar_preserva_o_cadastro(): void
    {
        $this->postJson('/api/vendedores/sincronizar', ['vendedores' => [
            ['codigo_externo' => 'V1', 'nome' => 'Carlos', 'email' => 'carlos@exemplo.com'],
        ]]);
        $this->postJson('/api/vendedores/sincronizar', ['vendedores' => [['codigo_externo' => 'V1', 'ativo' => false]]])->assertOk();

        $vendedor = Vendedor::firstOrFail();
        $this->assertFalse($vendedor->ativo);
        $this->assertSame('Carlos', $vendedor->nome);
        $this->assertSame('carlos@exemplo.com', $vendedor->email);
    }

    public function test_codigo_unico_por_empresa_e_empresa_vizinha_isolada(): void
    {
        $outra = Empresa::factory()->create();
        Vendedor::create(['empresa_id' => $outra->id, 'codigo_externo' => 'V1', 'nome' => 'Da vizinha']);

        $this->postJson('/api/vendedores/sincronizar', ['vendedores' => [['codigo_externo' => 'V1', 'nome' => 'Meu']]])
            ->assertOk()->assertJsonPath('criadas', 1);

        $this->getJson('/api/vendedores')->assertOk()
            ->assertJsonCount(1, 'vendedores')
            ->assertJsonPath('vendedores.0.nome', 'Meu');
        $this->assertSame('Da vizinha', Vendedor::withoutGlobalScopes()->where('empresa_id', $outra->id)->value('nome'));
    }

    public function test_planilha_e_tudo_ou_nada_com_previa(): void
    {
        $cabecalho = 'Código Externo;Nome;Telefone;E-mail;codsupervisor;Ativo';

        $this->csv('/api/vendedores/importar', [$cabecalho, 'V1;Carlos;;;;sim'], simular: true)->assertOk()
            ->assertJsonPath('criadas', 1)->assertJsonPath('aplicado', false);
        $this->assertSame(0, Vendedor::count());

        $this->csv('/api/vendedores/importar', [$cabecalho, 'V1;Carlos;;;;sim', 'V2;Dora;;email-ruim;;'])->assertOk()
            ->assertJsonPath('aplicado', false)
            ->assertJsonPath('erros.0.linha', 3);
        $this->assertSame(0, Vendedor::count());

        $this->csv('/api/vendedores/importar', [$cabecalho, 'V1;Carlos;;;S1;sim', 'V2;Dóra Acentuada;;dora@exemplo.com;;'])->assertOk()
            ->assertJsonPath('aplicado', true)
            ->assertJsonPath('criadas', 2)
            ->assertJsonCount(1, 'avisos');
        $this->assertSame('Dóra Acentuada', Vendedor::where('codigo_externo', 'V2')->value('nome'));
        $this->assertSame('S1', Vendedor::where('codigo_externo', 'V1')->value('codigo_supervisor'));
    }

    public function test_tela_edita_cadastro_contato_e_vinculo_com_usuario(): void
    {
        $vendedor = Vendedor::create(['empresa_id' => $this->empresa->id, 'codigo_externo' => 'V1', 'nome' => 'Carlos']);
        $outro = Vendedor::create(['empresa_id' => $this->empresa->id, 'codigo_externo' => 'V2', 'nome' => 'Dora']);
        $usuario = Usuario::factory()->promotor()->create(['empresa_id' => $this->empresa->id]);

        $this->putJson("/api/vendedores/{$vendedor->uuid}", [
            'telefone' => '92 3333-4444', 'usuario_uuid' => $usuario->uuid, 'codigo_externo' => 'V10', 'nome' => 'Carlos Silva', 'ativo' => false,
        ])->assertOk()
            ->assertJsonPath('vendedor.usuario.id', $usuario->uuid)
            ->assertJsonPath('vendedor.codigo_externo', 'V10')
            ->assertJsonPath('vendedor.nome', 'Carlos Silva')
            ->assertJsonPath('vendedor.ativo', false)
            ->assertJsonPath('vendedor.telefone', '92 3333-4444');

        // Código é a chave do ERP: não pode repetir.
        $this->putJson("/api/vendedores/{$outro->uuid}", ['codigo_externo' => 'V10'])->assertStatus(422)
            ->assertJsonValidationErrors('codigo_externo');
        // Um login, um cadastro.
        $this->putJson("/api/vendedores/{$outro->uuid}", ['usuario_uuid' => $usuario->uuid])->assertStatus(422);

        $this->putJson("/api/vendedores/{$vendedor->uuid}", ['usuario_uuid' => null])->assertOk()
            ->assertJsonPath('vendedor.usuario', null);
    }

    public function test_tela_cria_vendedor_com_supervisor_e_supervisor_religa_quem_chegou_antes(): void
    {
        $this->postJson('/api/vendedores', ['nome' => 'Sem código'])->assertStatus(422)->assertJsonValidationErrors('codigo_externo');

        // Vendedor do ERP que aponta pra um supervisor ainda inexistente.
        $this->postJson('/api/vendedores/sincronizar', ['vendedores' => [['codigo_externo' => 'V1', 'nome' => 'Carlos', 'codigo_supervisor' => 'S1']]]);

        $supervisor = $this->postJson('/api/supervisores', ['codigo_externo' => 'S1', 'nome' => 'Ana', 'email' => 'ana@exemplo.com'])
            ->assertCreated()
            ->assertJsonPath('supervisor.ativo', true)
            ->assertJsonPath('supervisor.vendedores_count', 1)
            ->json('supervisor');

        $this->postJson('/api/vendedores', ['codigo_externo' => 'V2', 'nome' => 'Dora', 'supervisor_uuid' => $supervisor['id']])
            ->assertCreated()
            ->assertJsonPath('vendedor.supervisor.nome', 'Ana')
            ->assertJsonPath('vendedor.codigo_supervisor', 'S1')
            ->assertJsonPath('vendedor.lojas_count', 0);
        $this->postJson('/api/vendedores', ['codigo_externo' => 'V2', 'nome' => 'Repetido'])->assertStatus(422);

        // Trocar o código do supervisor leva o código cru dos vendedores junto.
        $this->putJson("/api/supervisores/{$supervisor['id']}", ['codigo_externo' => 'S100'])->assertOk();
        $this->assertSame(['S100', 'S100'], Vendedor::orderBy('codigo_externo')->pluck('codigo_supervisor')->all());

        // Tirar o supervisor pela tela limpa o vínculo e o código.
        $v2 = Vendedor::where('codigo_externo', 'V2')->firstOrFail();
        $this->putJson("/api/vendedores/{$v2->uuid}", ['supervisor_uuid' => null])->assertOk()
            ->assertJsonPath('vendedor.supervisor', null)
            ->assertJsonPath('vendedor.codigo_supervisor', null);
    }

    public function test_excluir_vendedor_leva_a_carteira_e_excluir_supervisor_solta_os_vendedores(): void
    {
        $loja = PontoVenda::factory()->create(['empresa_id' => $this->empresa->id]);
        $supervisor = Supervisor::create(['empresa_id' => $this->empresa->id, 'codigo_externo' => 'S1', 'nome' => 'Ana']);
        $v1 = Vendedor::create(['empresa_id' => $this->empresa->id, 'codigo_externo' => 'V1', 'nome' => 'Carlos', 'codigo_supervisor' => 'S1', 'supervisor_id' => $supervisor->id]);
        $v2 = Vendedor::create(['empresa_id' => $this->empresa->id, 'codigo_externo' => 'V2', 'nome' => 'Dora']);
        $loja->vendedores()->attach([$v1->id, $v2->id]);

        $this->deleteJson("/api/vendedores/{$v2->uuid}")->assertNoContent();
        $this->assertModelMissing($v2);
        $this->assertSame([$v1->id], $loja->vendedores()->pluck('vendedores.id')->all());

        $this->deleteJson("/api/supervisores/{$supervisor->uuid}")->assertNoContent();
        $v1->refresh();
        $this->assertNull($v1->supervisor_id);
        $this->assertSame('S1', $v1->codigo_supervisor);

        // Empresa vizinha não enxerga (nem exclui) o cadastro.
        $vizinha = Empresa::factory()->create();
        Sanctum::actingAs(Usuario::factory()->admin()->create(['empresa_id' => $vizinha->id]));
        $this->deleteJson("/api/vendedores/{$v1->uuid}")->assertNotFound();
        $this->putJson("/api/vendedores/{$v1->uuid}", ['nome' => 'X'])->assertNotFound();
    }

    public function test_lista_filtra_por_busca_supervisor_e_sem_supervisor(): void
    {
        $this->postJson('/api/supervisores/sincronizar', ['supervisores' => [['codigo_externo' => 'S1', 'nome' => 'Ana']]]);
        $this->postJson('/api/vendedores/sincronizar', ['vendedores' => [
            ['codigo_externo' => 'V1', 'nome' => 'Carlos', 'codigo_supervisor' => 'S1'],
            ['codigo_externo' => 'V2', 'nome' => 'Dora', 'codigo_supervisor' => 'S404'],
        ]]);
        $s1 = Supervisor::firstOrFail();

        $this->getJson('/api/vendedores?busca=carl')->assertJsonCount(1, 'vendedores');
        $this->getJson('/api/vendedores?busca=V2')->assertJsonPath('vendedores.0.nome', 'Dora');
        $this->getJson("/api/vendedores?supervisor_uuid={$s1->uuid}")->assertJsonPath('vendedores.0.supervisor.nome', 'Ana');
        $this->getJson('/api/vendedores?sem_supervisor=1')->assertJsonCount(1, 'vendedores')
            ->assertJsonPath('vendedores.0.codigo_supervisor', 'S404');
        $this->getJson('/api/supervisores')->assertJsonPath('supervisores.0.vendedores_count', 1);
    }

    public function test_permissao_exigida(): void
    {
        $gestor = Usuario::factory()->gestor()->create(['empresa_id' => $this->empresa->id]);
        Sanctum::actingAs($gestor);
        $this->getJson('/api/vendedores')->assertForbidden();
        $this->postJson('/api/vendedores/sincronizar', ['vendedores' => [['codigo_externo' => 'V1', 'nome' => 'X']]])->assertForbidden();

        $perfil = Perfil::create(['empresa_id' => $this->empresa->id, 'nome' => 'Integrador ERP', 'permissoes' => [Permissao::VENDEDORES_GERENCIAR->value]]);
        $gestor->update(['perfil_id' => $perfil->id]);
        Sanctum::actingAs($gestor->fresh());
        $this->getJson('/api/vendedores')->assertOk();

        Sanctum::actingAs(Usuario::factory()->promotor()->create(['empresa_id' => $this->empresa->id]));
        $this->getJson('/api/supervisores')->assertForbidden();
    }

    // ---- Carteira vendedor × loja (§11.1) ----

    private function vendedor(string $codigo, string $nome = 'Vendedor'): Vendedor
    {
        return Vendedor::create(['empresa_id' => $this->empresa->id, 'codigo_externo' => $codigo, 'nome' => $nome, 'telefone' => "92 9{$codigo}", 'email' => "{$codigo}@exemplo.com"]);
    }

    public function test_carteira_aceita_varios_vendedores_e_substitui_por_loja(): void
    {
        $loja1 = PontoVenda::factory()->create(['empresa_id' => $this->empresa->id, 'codigo_externo' => 'L1']);
        $loja2 = PontoVenda::factory()->create(['empresa_id' => $this->empresa->id, 'codigo_externo' => 'L2']);
        $v1 = $this->vendedor('V1');
        $v2 = $this->vendedor('V2');
        $v3 = $this->vendedor('V3');
        $loja2->vendedores()->attach($v3->id);

        $this->postJson('/api/carteira-vendedores/sincronizar', ['vinculos' => [
            ['codigo_externo_loja' => 'L1', 'codigo_externo_vendedor' => 'V1'],
            ['codigo_externo_loja' => 'L1', 'codigo_externo_vendedor' => 'V2'],
            ['codigo_externo_loja' => 'L1', 'codigo_externo_vendedor' => 'V2'], // repetido: um vínculo só
        ]])->assertOk()->assertJsonPath('criadas', 2)->assertJsonPath('lojas', 1);

        $this->assertEqualsCanonicalizing([$v1->id, $v2->id], $loja1->vendedores()->pluck('vendedores.id')->all());
        // Loja fora do lote não é tocada.
        $this->assertSame([$v3->id], $loja2->vendedores()->pluck('vendedores.id')->all());

        // V2 saiu da carteira da L1 no ERP.
        $this->postJson('/api/carteira-vendedores/sincronizar', ['vinculos' => [
            ['codigo_externo_loja' => 'L1', 'codigo_externo_vendedor' => 'V1'],
        ]])->assertOk()->assertJsonPath('criadas', 0)->assertJsonPath('removidas', 1);
        $this->assertSame([$v1->id], $loja1->vendedores()->pluck('vendedores.id')->all());
    }

    public function test_carteira_loja_com_linha_ruim_fica_como_estava(): void
    {
        $loja = PontoVenda::factory()->create(['empresa_id' => $this->empresa->id, 'codigo_externo' => 'L1']);
        $v1 = $this->vendedor('V1');
        $loja->vendedores()->attach($v1->id);
        $this->vendedor('V2');

        $this->postJson('/api/carteira-vendedores/sincronizar', ['vinculos' => [
            ['codigo_externo_loja' => 'L1', 'codigo_externo_vendedor' => 'V2'],
            ['codigo_externo_loja' => 'L1', 'codigo_externo_vendedor' => 'V404'],
            ['codigo_externo_loja' => 'L404', 'codigo_externo_vendedor' => 'V2'],
        ]])->assertOk()->assertJsonCount(2, 'erros')->assertJsonPath('lojas', 0);

        $this->assertSame([$v1->id], $loja->vendedores()->pluck('vendedores.id')->all());
    }

    public function test_carteira_por_planilha_e_tudo_ou_nada(): void
    {
        PontoVenda::factory()->create(['empresa_id' => $this->empresa->id, 'codigo_externo' => 'L1']);
        $this->vendedor('V1');

        $this->csv('/api/carteira-vendedores/importar', ['codigo_externo_loja;codigo_externo_vendedor', 'L1;V1', 'L1;V9'])
            ->assertOk()->assertJsonPath('aplicado', false)->assertJsonPath('erros.0.linha', 3);
        $this->assertSame(0, \DB::table('vendedor_ponto_venda')->count());

        $this->csv('/api/carteira-vendedores/importar', ['codigo_externo_loja;codigo_externo_vendedor', 'L1;V1'])
            ->assertOk()->assertJsonPath('aplicado', true)->assertJsonPath('criadas', 1);
    }

    public function test_detalhe_da_loja_mostra_vendedores_e_esconde_email_do_promotor(): void
    {
        $loja = PontoVenda::factory()->create(['empresa_id' => $this->empresa->id]);
        $supervisor = Supervisor::create(['empresa_id' => $this->empresa->id, 'codigo_externo' => 'S1', 'nome' => 'Ana']);
        $v1 = $this->vendedor('V1', 'Carlos');
        $v1->update(['supervisor_id' => $supervisor->id]);
        $v2 = $this->vendedor('V2', 'Bia');

        $this->postJson("/api/pontos-venda/{$loja->uuid}/vendedores/{$v1->uuid}")->assertOk()->assertJsonCount(1, 'vendedores');
        $this->postJson("/api/pontos-venda/{$loja->uuid}/vendedores/{$v2->uuid}")->assertOk()->assertJsonCount(2, 'vendedores');
        $this->postJson("/api/pontos-venda/{$loja->uuid}/vendedores/{$v2->uuid}")->assertOk()->assertJsonCount(2, 'vendedores');

        $this->getJson("/api/pontos-venda/{$loja->uuid}")->assertOk()
            ->assertJsonPath('ponto_venda.vendedores.0.nome', 'Bia')
            ->assertJsonPath('ponto_venda.vendedores.1.supervisor.nome', 'Ana')
            ->assertJsonPath('ponto_venda.vendedores.1.email', 'V1@exemplo.com');

        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $this->empresa->id]);
        Sanctum::actingAs($promotor);
        $vendedores = $this->getJson("/api/pontos-venda/{$loja->uuid}")->assertOk()->json('ponto_venda.vendedores');
        $this->assertSame('92 9V1', $vendedores[1]['telefone']);
        $this->assertArrayNotHasKey('email', $vendedores[1]);
        // Promotor não mexe na carteira.
        $this->deleteJson("/api/pontos-venda/{$loja->uuid}/vendedores/{$v1->uuid}")->assertForbidden();
    }
}

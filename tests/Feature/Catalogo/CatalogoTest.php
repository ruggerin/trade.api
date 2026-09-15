<?php

namespace Tests\Feature\Catalogo;

use App\Enums\Permissao;
use App\Models\DepartamentoAuditoria;
use App\Models\Empresa;
use App\Models\MarcaAuditoria;
use App\Models\NivelExibicao;
use App\Models\Perfil;
use App\Models\ProdutoAuditoria;
use App\Models\RamoAtividade;
use App\Models\RedeLoja;
use App\Models\SecaoAuditoria;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Departamento/Seção/Marca/Produto/Nível de Exibição seguem o mesmo padrão de controller
 * (leitura aberta, escrita por catalogo.gerenciar, soft delete, filtro cross-empresa só com
 * efeito pro SUPERADMIN) — um arquivo cobrindo o padrão representado nos 5 recursos, em vez de
 * duplicar o mesmo teste 5 vezes.
 */
class CatalogoTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_cria_departamento(): void
    {
        $empresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($admin);

        $this->postJson('/api/departamentos-auditoria', ['descricao' => 'Bebidas'])
            ->assertCreated()
            ->assertJsonPath('departamento.descricao', 'Bebidas');
    }

    public function test_promotor_le_catalogo_mas_nao_escreve(): void
    {
        $empresa = Empresa::factory()->create();
        DepartamentoAuditoria::factory()->create(['empresa_id' => $empresa->id]);
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($promotor);

        $this->getJson('/api/departamentos-auditoria')->assertOk();
        $this->postJson('/api/departamentos-auditoria', ['descricao' => 'Tentativa'])->assertForbidden();
    }

    public function test_gestor_com_permissao_de_catalogo_gerencia_secao_vinculada_a_departamento(): void
    {
        $empresa = Empresa::factory()->create();
        $perfil = Perfil::factory()->comPermissoes([Permissao::CATALOGO_GERENCIAR->value])->create(['empresa_id' => $empresa->id]);
        $gestor = Usuario::factory()->gestor()->create(['empresa_id' => $empresa->id, 'perfil_id' => $perfil->id]);
        $departamento = DepartamentoAuditoria::factory()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($gestor);

        $response = $this->postJson('/api/secoes-auditoria', [
            'descricao' => 'Refrigerantes',
            'departamento_uuid' => $departamento->uuid,
        ]);

        $response->assertCreated()->assertJsonPath('secao.departamento.id', $departamento->uuid);
    }

    public function test_marca_exige_propriedade_valida(): void
    {
        $empresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($admin);

        $this->postJson('/api/marcas-auditoria', [
            'descricao' => 'Marca X',
            'propriedade' => 'INVALIDA',
        ])->assertStatus(422)->assertJsonValidationErrors('propriedade');
    }

    public function test_produto_resolve_departamento_secao_e_nivel_de_exibicao_por_uuid(): void
    {
        $empresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        $departamento = DepartamentoAuditoria::factory()->create(['empresa_id' => $empresa->id]);
        $secao = SecaoAuditoria::factory()->create(['empresa_id' => $empresa->id, 'departamento_id' => $departamento->id]);
        $nivel = NivelExibicao::factory()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/produtos-auditoria', [
            'descricao' => 'Refrigerante 2L',
            'propriedade' => 'PROPRIA',
            'departamento_uuid' => $departamento->uuid,
            'secao_uuid' => $secao->uuid,
            'nivel_exibicao_uuid' => $nivel->uuid,
        ]);

        $response->assertCreated()
            ->assertJsonPath('produto.departamento.id', $departamento->uuid)
            ->assertJsonPath('produto.secao.id', $secao->uuid)
            ->assertJsonPath('produto.nivel_exibicao.id', $nivel->uuid);
    }

    public function test_destroy_de_produto_e_soft_delete(): void
    {
        $empresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        $produto = ProdutoAuditoria::factory()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($admin);

        $this->deleteJson("/api/produtos-auditoria/{$produto->uuid}")->assertNoContent();
        $this->assertDatabaseHas('produtos_auditoria', ['id' => $produto->id, 'ativo' => false]);
    }

    public function test_superadmin_le_catalogo_de_qualquer_empresa_mas_nao_escreve(): void
    {
        $empresaA = Empresa::factory()->create();
        $empresaB = Empresa::factory()->create();
        DepartamentoAuditoria::factory()->create(['empresa_id' => $empresaA->id]);
        DepartamentoAuditoria::factory()->create(['empresa_id' => $empresaB->id]);

        $superadmin = Usuario::factory()->superadmin()->create();
        Sanctum::actingAs($superadmin);

        // Sem filtro: SUPERADMIN não pertence a nenhum tenant, então o global scope não filtra
        // nada — vê as duas.
        $response = $this->getJson('/api/departamentos-auditoria')->assertOk();
        $this->assertCount(2, $response->json('departamentos'));

        // Com filtro por empresa_uuid: só a empresa escolhida.
        $filtrado = $this->getJson("/api/departamentos-auditoria?empresa_uuid={$empresaA->uuid}")->assertOk();
        $this->assertCount(1, $filtrado->json('departamentos'));

        // Escrita continua bloqueada — catalogo.gerenciar não é uma das exceções do SUPERADMIN.
        $this->postJson('/api/departamentos-auditoria', ['descricao' => 'Tentativa'])->assertForbidden();
    }

    public function test_nivel_de_exibicao_e_isolado_por_empresa(): void
    {
        $empresaA = Empresa::factory()->create();
        $empresaB = Empresa::factory()->create();
        NivelExibicao::factory()->create(['empresa_id' => $empresaA->id, 'descricao' => 'Prateleira']);
        NivelExibicao::factory()->create(['empresa_id' => $empresaB->id, 'descricao' => 'Ponta de Gôndola']);

        $adminA = Usuario::factory()->admin()->create(['empresa_id' => $empresaA->id]);
        Sanctum::actingAs($adminA);

        $response = $this->getJson('/api/niveis-exibicao')->assertOk();
        $this->assertCount(1, $response->json('niveis_exibicao'));
        $this->assertEquals('Prateleira', $response->json('niveis_exibicao.0.descricao'));
    }

    /**
     * Rede de Lojas e Ramo de Atividade (classificação de PontoVenda, ver
     * PontoVendaController) seguem exatamente o mesmo padrão de Departamento/Nível de
     * Exibição — cobertos aqui em vez de arquivos próprios, mesmo raciocínio do docblock desta
     * classe.
     */
    public function test_admin_cria_rede_de_loja_e_ramo_de_atividade(): void
    {
        $empresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($admin);

        $this->postJson('/api/redes-lojas', ['descricao' => 'Grupo Pão de Açúcar'])
            ->assertCreated()
            ->assertJsonPath('rede_loja.descricao', 'Grupo Pão de Açúcar');

        $this->postJson('/api/ramos-atividade', ['descricao' => 'Supermercado'])
            ->assertCreated()
            ->assertJsonPath('ramo_atividade.descricao', 'Supermercado');
    }

    public function test_promotor_le_rede_de_loja_e_ramo_de_atividade_mas_nao_escreve(): void
    {
        $empresa = Empresa::factory()->create();
        RedeLoja::factory()->create(['empresa_id' => $empresa->id]);
        RamoAtividade::factory()->create(['empresa_id' => $empresa->id]);
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($promotor);

        $this->getJson('/api/redes-lojas')->assertOk();
        $this->getJson('/api/ramos-atividade')->assertOk();
        $this->postJson('/api/redes-lojas', ['descricao' => 'Tentativa'])->assertForbidden();
        $this->postJson('/api/ramos-atividade', ['descricao' => 'Tentativa'])->assertForbidden();
    }

    public function test_destroy_de_rede_de_loja_e_ramo_de_atividade_e_soft_delete(): void
    {
        $empresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        $rede = RedeLoja::factory()->create(['empresa_id' => $empresa->id]);
        $ramo = RamoAtividade::factory()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($admin);

        $this->deleteJson("/api/redes-lojas/{$rede->uuid}")->assertNoContent();
        $this->deleteJson("/api/ramos-atividade/{$ramo->uuid}")->assertNoContent();
        $this->assertDatabaseHas('redes_lojas', ['id' => $rede->id, 'ativo' => false]);
        $this->assertDatabaseHas('ramos_atividade', ['id' => $ramo->id, 'ativo' => false]);
    }

    public function test_rede_de_loja_e_ramo_de_atividade_sao_isolados_por_empresa(): void
    {
        $empresaA = Empresa::factory()->create();
        $empresaB = Empresa::factory()->create();
        RedeLoja::factory()->create(['empresa_id' => $empresaA->id, 'descricao' => 'Rede A']);
        RedeLoja::factory()->create(['empresa_id' => $empresaB->id, 'descricao' => 'Rede B']);

        $adminA = Usuario::factory()->admin()->create(['empresa_id' => $empresaA->id]);
        Sanctum::actingAs($adminA);

        $response = $this->getJson('/api/redes-lojas')->assertOk();
        $this->assertCount(1, $response->json('redes_lojas'));
        $this->assertEquals('Rede A', $response->json('redes_lojas.0.descricao'));
    }
}

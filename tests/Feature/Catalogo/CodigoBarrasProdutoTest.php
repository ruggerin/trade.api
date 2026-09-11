<?php

namespace Tests\Feature\Catalogo;

use App\Models\Empresa;
use App\Models\Parametro;
use App\Models\ProdutoAuditoria;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Código de barras do produto — opcional por padrão, obrigatório e/ou único no cadastro
 * conforme os parâmetros CODIGO_BARRAS_OBRIGATORIO/CODIGO_BARRAS_UNICO da empresa (mesma base
 * pro cadastro do admin web e pro self-service do promotor). Ver App\Support\CodigoBarrasProduto.
 *
 * A obrigatoriedade (não a unicidade) tem uma condição extra no admin web: só vale quando
 * `produto_final = true` (SKU específico, não a combinação genérica "seção × marca"). O
 * self-service do promotor não tem esse campo — todo produto criado por ele já é, por
 * natureza, um SKU específico, ver StoreProdutoAuditoriaPropriaRequest.
 */
class CodigoBarrasProdutoTest extends TestCase
{
    use RefreshDatabase;

    private function ligarParametro(Empresa $empresa, string $chave): void
    {
        Parametro::create(['empresa_id' => $empresa->id, 'chave' => $chave, 'valor' => 'true']);
    }

    public function test_por_padrao_codigo_de_barras_e_opcional(): void
    {
        $empresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($admin);

        $this->postJson('/api/produtos-auditoria', [
            'descricao' => 'Sem código', 'propriedade' => 'PROPRIA',
        ])->assertCreated()->assertJsonPath('produto.codigo_barras', null);
    }

    public function test_admin_cadastra_com_codigo_de_barras(): void
    {
        $empresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($admin);

        $this->postJson('/api/produtos-auditoria', [
            'descricao' => 'Com código', 'propriedade' => 'PROPRIA', 'codigo_barras' => '7891000100103',
        ])->assertCreated()->assertJsonPath('produto.codigo_barras', '7891000100103');
    }

    public function test_obrigatorio_rejeita_cadastro_de_produto_final_sem_codigo_de_barras(): void
    {
        $empresa = Empresa::factory()->create();
        $this->ligarParametro($empresa, 'CODIGO_BARRAS_OBRIGATORIO');
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($admin);

        $this->postJson('/api/produtos-auditoria', [
            'descricao' => 'Sem código', 'propriedade' => 'PROPRIA', 'produto_final' => true,
        ])->assertStatus(422)->assertJsonValidationErrors('codigo_barras');

        $this->assertDatabaseCount('produtos_auditoria', 0);
    }

    /**
     * Obrigatoriedade só vale pra SKU específico (`produto_final = true`) — um produto genérico
     * "seção × marca" não tem código de barras próprio nenhum pra exigir.
     */
    public function test_obrigatorio_nao_vale_pra_produto_que_nao_e_final(): void
    {
        $empresa = Empresa::factory()->create();
        $this->ligarParametro($empresa, 'CODIGO_BARRAS_OBRIGATORIO');
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($admin);

        $this->postJson('/api/produtos-auditoria', [
            'descricao' => 'Seção × marca genérico', 'propriedade' => 'PROPRIA', 'produto_final' => false,
        ])->assertCreated()->assertJsonPath('produto.codigo_barras', null);

        // Nem enviar produto_final (ausente = false, mesmo default do banco) muda o resultado.
        $this->postJson('/api/produtos-auditoria', [
            'descricao' => 'Sem produto_final', 'propriedade' => 'PROPRIA',
        ])->assertCreated()->assertJsonPath('produto.codigo_barras', null);
    }

    public function test_obrigatorio_vale_tambem_pro_self_service_do_promotor(): void
    {
        $empresa = Empresa::factory()->create();
        $this->ligarParametro($empresa, 'CODIGO_BARRAS_OBRIGATORIO');
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($promotor);

        $this->postJson('/api/produtos-auditoria/proprio', [
            'descricao' => 'Sem código', 'propriedade' => 'PROPRIA',
        ])->assertStatus(422)->assertJsonValidationErrors('codigo_barras');

        $this->postJson('/api/produtos-auditoria/proprio', [
            'descricao' => 'Com código', 'propriedade' => 'PROPRIA', 'codigo_barras' => '7891000100103',
        ])->assertCreated();
    }

    public function test_unico_rejeita_codigo_de_barras_duplicado_na_mesma_empresa(): void
    {
        $empresa = Empresa::factory()->create();
        $this->ligarParametro($empresa, 'CODIGO_BARRAS_UNICO');
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        ProdutoAuditoria::factory()->create(['empresa_id' => $empresa->id, 'codigo_barras' => '7891000100103']);
        Sanctum::actingAs($admin);

        $this->postJson('/api/produtos-auditoria', [
            'descricao' => 'Outro produto', 'propriedade' => 'PROPRIA', 'codigo_barras' => '7891000100103',
        ])->assertStatus(422)->assertJsonValidationErrors('codigo_barras');
    }

    public function test_unico_nao_conflita_entre_empresas_diferentes(): void
    {
        $empresaA = Empresa::factory()->create();
        $empresaB = Empresa::factory()->create();
        $this->ligarParametro($empresaA, 'CODIGO_BARRAS_UNICO');
        ProdutoAuditoria::factory()->create(['empresa_id' => $empresaB->id, 'codigo_barras' => '7891000100103']);
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresaA->id]);
        Sanctum::actingAs($admin);

        $this->postJson('/api/produtos-auditoria', [
            'descricao' => 'Produto de A', 'propriedade' => 'PROPRIA', 'codigo_barras' => '7891000100103',
        ])->assertCreated();
    }

    public function test_sem_unico_ligado_aceita_codigo_de_barras_repetido(): void
    {
        $empresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        ProdutoAuditoria::factory()->create(['empresa_id' => $empresa->id, 'codigo_barras' => '7891000100103']);
        Sanctum::actingAs($admin);

        $this->postJson('/api/produtos-auditoria', [
            'descricao' => 'Outro produto', 'propriedade' => 'PROPRIA', 'codigo_barras' => '7891000100103',
        ])->assertCreated();
    }

    public function test_update_nao_exige_codigo_de_barras_mesmo_com_obrigatorio_ligado(): void
    {
        $empresa = Empresa::factory()->create();
        $this->ligarParametro($empresa, 'CODIGO_BARRAS_OBRIGATORIO');
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        $produto = ProdutoAuditoria::factory()->create(['empresa_id' => $empresa->id, 'codigo_barras' => null]);
        Sanctum::actingAs($admin);

        $this->putJson("/api/produtos-auditoria/{$produto->uuid}", [
            'descricao' => 'Descrição atualizada',
        ])->assertOk();
    }

    public function test_update_valida_unicidade_ignorando_o_proprio_registro(): void
    {
        $empresa = Empresa::factory()->create();
        $this->ligarParametro($empresa, 'CODIGO_BARRAS_UNICO');
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        $produtoA = ProdutoAuditoria::factory()->create(['empresa_id' => $empresa->id, 'codigo_barras' => '111']);
        $produtoB = ProdutoAuditoria::factory()->create(['empresa_id' => $empresa->id, 'codigo_barras' => '222']);
        Sanctum::actingAs($admin);

        // Reenviar o próprio código do produtoA não deveria ser rejeitado (ignora a si mesmo).
        $this->putJson("/api/produtos-auditoria/{$produtoA->uuid}", ['codigo_barras' => '111'])->assertOk();

        // Tentar roubar o código do produtoB deveria ser rejeitado.
        $this->putJson("/api/produtos-auditoria/{$produtoA->uuid}", ['codigo_barras' => '222'])
            ->assertStatus(422)->assertJsonValidationErrors('codigo_barras');
    }
}

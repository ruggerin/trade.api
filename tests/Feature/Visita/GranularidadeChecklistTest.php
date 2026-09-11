<?php

namespace Tests\Feature\Visita;

use App\Models\DepartamentoAuditoria;
use App\Models\Empresa;
use App\Models\PontoVenda;
use App\Models\ProdutoAuditoria;
use App\Models\SecaoAuditoria;
use App\Models\TipoRegistro;
use App\Models\TipoRegistroSecaoExcecao;
use App\Models\Usuario;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * `TipoRegistro.granularidade_padrao` + exceção por seção — se a pergunta exige resposta por
 * produto individual, o registro não pode ficar vinculado só a seção/departamento/marca inteira
 * nem sair sem vínculo nenhum. Ver App\Support\GranularidadeChecklist e
 * docs/16-GRANULARIDADE-CHECKLIST-AUDITORIA.md §4.
 */
class GranularidadeChecklistTest extends TestCase
{
    use RefreshDatabase;

    private function abrirVisita(Usuario $promotor, PontoVenda $pdv): string
    {
        Sanctum::actingAs($promotor);

        return $this->postJson('/api/visitas', [
            'ponto_venda_uuid' => $pdv->uuid,
            'latitude' => $pdv->latitude,
            'longitude' => $pdv->longitude,
        ])->json('visita.id');
    }

    private function cenario(): array
    {
        $empresa = Empresa::factory()->create();
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        $departamento = DepartamentoAuditoria::create(['empresa_id' => $empresa->id, 'descricao' => 'Limpeza']);
        $secao = SecaoAuditoria::create(['empresa_id' => $empresa->id, 'descricao' => 'Amaciante', 'departamento_id' => $departamento->id]);
        $produto = ProdutoAuditoria::factory()->create(['empresa_id' => $empresa->id, 'secao_id' => $secao->id]);

        return compact('empresa', 'pdv', 'promotor', 'departamento', 'secao', 'produto');
    }

    public function test_granularidade_produto_rejeita_registro_sem_produto_vinculado(): void
    {
        ['empresa' => $empresa, 'pdv' => $pdv, 'promotor' => $promotor, 'secao' => $secao] = $this->cenario();
        $tipo = TipoRegistro::create([
            'empresa_id' => $empresa->id, 'descricao' => 'Produto Precificado',
            'permite_vincular_catalogo' => true, 'granularidade_padrao' => 'PRODUTO',
        ]);
        $visitaUuid = $this->abrirVisita($promotor, $pdv);

        $this->postJson("/api/visitas/{$visitaUuid}/registros", [
            'tipo_registro_uuid' => $tipo->uuid,
            'tipo_vinculo' => 'SECAO',
            'secao_uuid' => $secao->uuid,
        ])->assertStatus(422)->assertJsonValidationErrors('produto_auditoria_uuid');

        $this->postJson("/api/visitas/{$visitaUuid}/registros", [
            'tipo_registro_uuid' => $tipo->uuid,
        ])->assertStatus(422)->assertJsonValidationErrors('produto_auditoria_uuid');
    }

    public function test_granularidade_produto_aceita_registro_com_produto_vinculado(): void
    {
        ['pdv' => $pdv, 'promotor' => $promotor, 'produto' => $produto, 'empresa' => $empresa] = $this->cenario();
        $tipo = TipoRegistro::create([
            'empresa_id' => $empresa->id, 'descricao' => 'Produto Precificado', 'granularidade_padrao' => 'PRODUTO',
        ]);
        $visitaUuid = $this->abrirVisita($promotor, $pdv);

        $this->postJson("/api/visitas/{$visitaUuid}/registros", [
            'tipo_registro_uuid' => $tipo->uuid,
            'produto_auditoria_uuid' => $produto->uuid,
        ])->assertCreated();
    }

    public function test_sem_granularidade_configurada_continua_livre(): void
    {
        ['pdv' => $pdv, 'promotor' => $promotor, 'secao' => $secao, 'empresa' => $empresa] = $this->cenario();
        $tipo = TipoRegistro::create([
            'empresa_id' => $empresa->id, 'descricao' => 'Executou Planograma', 'permite_vincular_catalogo' => true,
        ]);
        $visitaUuid = $this->abrirVisita($promotor, $pdv);

        $this->postJson("/api/visitas/{$visitaUuid}/registros", [
            'tipo_registro_uuid' => $tipo->uuid,
            'tipo_vinculo' => 'SECAO',
            'secao_uuid' => $secao->uuid,
        ])->assertCreated();
    }

    public function test_excecao_por_secao_sobrepoe_o_padrao(): void
    {
        ['pdv' => $pdv, 'promotor' => $promotor, 'secao' => $secao, 'produto' => $produto, 'empresa' => $empresa] = $this->cenario();
        // Padrão LINHA (livre), mas essa seção específica exige PRODUTO.
        $tipo = TipoRegistro::create([
            'empresa_id' => $empresa->id, 'descricao' => 'Ponto Natural',
            'permite_vincular_catalogo' => true, 'granularidade_padrao' => 'LINHA',
        ]);
        TipoRegistroSecaoExcecao::create([
            'tipo_registro_id' => $tipo->id, 'secao_auditoria_id' => $secao->id, 'granularidade' => 'PRODUTO',
        ]);
        $visitaUuid = $this->abrirVisita($promotor, $pdv);

        // Vínculo à seção inteira (permitido pelo padrão LINHA) é rejeitado pela exceção.
        $this->postJson("/api/visitas/{$visitaUuid}/registros", [
            'tipo_registro_uuid' => $tipo->uuid,
            'tipo_vinculo' => 'SECAO',
            'secao_uuid' => $secao->uuid,
        ])->assertStatus(422)->assertJsonValidationErrors('produto_auditoria_uuid');

        // Produto específico daquela seção passa normalmente.
        $this->postJson("/api/visitas/{$visitaUuid}/registros", [
            'tipo_registro_uuid' => $tipo->uuid,
            'produto_auditoria_uuid' => $produto->uuid,
        ])->assertCreated();
    }

    public function test_excecao_nao_afeta_outras_secoes(): void
    {
        ['pdv' => $pdv, 'promotor' => $promotor, 'secao' => $secao, 'empresa' => $empresa, 'departamento' => $departamento] = $this->cenario();
        $outraSecao = SecaoAuditoria::create(['empresa_id' => $empresa->id, 'descricao' => 'Detergente', 'departamento_id' => $departamento->id]);
        $tipo = TipoRegistro::create([
            'empresa_id' => $empresa->id, 'descricao' => 'Ponto Natural',
            'permite_vincular_catalogo' => true, 'granularidade_padrao' => 'LINHA',
        ]);
        TipoRegistroSecaoExcecao::create([
            'tipo_registro_id' => $tipo->id, 'secao_auditoria_id' => $secao->id, 'granularidade' => 'PRODUTO',
        ]);
        $visitaUuid = $this->abrirVisita($promotor, $pdv);

        $this->postJson("/api/visitas/{$visitaUuid}/registros", [
            'tipo_registro_uuid' => $tipo->uuid,
            'tipo_vinculo' => 'SECAO',
            'secao_uuid' => $outraSecao->uuid,
        ])->assertCreated();
    }

    public function test_admin_configura_granularidade_padrao_e_excecoes(): void
    {
        $empresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        $departamento = DepartamentoAuditoria::create(['empresa_id' => $empresa->id, 'descricao' => 'Limpeza']);
        $secao = SecaoAuditoria::create(['empresa_id' => $empresa->id, 'descricao' => 'Amaciante', 'departamento_id' => $departamento->id]);
        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/tipos-registro', [
            'descricao' => 'Ponto Natural',
            'granularidade_padrao' => 'LINHA',
            'excecoes_granularidade' => [
                ['secao_uuid' => $secao->uuid, 'granularidade' => 'PRODUTO'],
            ],
        ]);

        $response->assertCreated()
            ->assertJsonPath('tipo_registro.granularidade_padrao', 'LINHA')
            ->assertJsonPath('tipo_registro.excecoes_granularidade.0.secao_uuid', $secao->uuid)
            ->assertJsonPath('tipo_registro.excecoes_granularidade.0.granularidade', 'PRODUTO');

        $uuid = $response->json('tipo_registro.id');

        // Update substitui a lista inteira de exceções.
        $this->putJson("/api/tipos-registro/{$uuid}", ['excecoes_granularidade' => []])
            ->assertOk()
            ->assertJsonCount(0, 'tipo_registro.excecoes_granularidade');
    }

    public function test_excecao_com_secao_de_outra_empresa_retorna_422(): void
    {
        $empresa = Empresa::factory()->create();
        $outraEmpresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        $departamento = DepartamentoAuditoria::create(['empresa_id' => $outraEmpresa->id, 'descricao' => 'Limpeza']);
        $secaoDeOutraEmpresa = SecaoAuditoria::create(['empresa_id' => $outraEmpresa->id, 'descricao' => 'Amaciante', 'departamento_id' => $departamento->id]);
        Sanctum::actingAs($admin);

        $this->postJson('/api/tipos-registro', [
            'descricao' => 'Ponto Natural',
            'excecoes_granularidade' => [
                ['secao_uuid' => $secaoDeOutraEmpresa->uuid, 'granularidade' => 'PRODUTO'],
            ],
        ])->assertStatus(422)->assertJsonValidationErrors('excecoes_granularidade.0.secao_uuid');
    }

    /**
     * `produto_chave` — ver docs/16-GRANULARIDADE-CHECKLIST-AUDITORIA.md §6, usado ao finalizar
     * a visita pra nomear o produto ausente em vez de só contar.
     */
    public function test_admin_marca_produto_como_chave(): void
    {
        $empresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/produtos-auditoria', [
            'descricao' => 'Biscoito Amanteigado', 'propriedade' => 'PROPRIA', 'produto_chave' => true,
        ]);

        $response->assertCreated()->assertJsonPath('produto.produto_chave', true);
    }

    public function test_disponiveis_expoe_produto_chave(): void
    {
        ['empresa' => $empresa, 'pdv' => $pdv, 'promotor' => $promotor, 'produto' => $produto] = $this->cenario();
        $produto->update(['produto_chave' => true]);
        $campanha = \App\Models\CampanhaAuditoria::factory()->create([
            'empresa_id' => $empresa->id, 'vigencia_inicio' => now()->subDay(), 'vigencia_fim' => now()->addDay(),
        ]);
        \App\Models\CampanhaItem::create([
            'campanha_id' => $campanha->id, 'tipo_item' => 'PRODUTO', 'produto_id' => $produto->id,
        ]);
        Sanctum::actingAs($promotor);

        $this->getJson("/api/campanhas-auditoria/disponiveis?ponto_venda_uuid={$pdv->uuid}")
            ->assertOk()
            ->assertJsonPath('produtos.0.produto_chave', true);
    }

    /**
     * `eh_ruptura` — marca a coluna "Ruptura" da grade de coleta (Fase 2). Toda empresa nasce
     * com o tipo "Ruptura" já marcado, pronto pra usar sem o gestor configurar nada.
     */
    public function test_empresa_nasce_com_ruptura_marcada_e_granularidade_produto(): void
    {
        $empresa = Empresa::factory()->create();

        $ruptura = TipoRegistro::where('empresa_id', $empresa->id)->where('descricao', 'Ruptura')->first();

        $this->assertTrue($ruptura->eh_ruptura);
        $this->assertSame('PRODUTO', $ruptura->granularidade_padrao->value);
    }

    public function test_admin_marca_tipo_como_ruptura(): void
    {
        $empresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/tipos-registro', [
            'descricao' => 'Falta de estoque', 'eh_ruptura' => true, 'granularidade_padrao' => 'PRODUTO',
        ]);

        $response->assertCreated()->assertJsonPath('tipo_registro.eh_ruptura', true);
    }

    public function test_disponiveis_expoe_secao_do_produto(): void
    {
        ['empresa' => $empresa, 'pdv' => $pdv, 'promotor' => $promotor, 'produto' => $produto, 'secao' => $secao] = $this->cenario();
        $campanha = \App\Models\CampanhaAuditoria::factory()->create([
            'empresa_id' => $empresa->id, 'vigencia_inicio' => now()->subDay(), 'vigencia_fim' => now()->addDay(),
        ]);
        \App\Models\CampanhaItem::create([
            'campanha_id' => $campanha->id, 'tipo_item' => 'PRODUTO', 'produto_id' => $produto->id,
        ]);
        Sanctum::actingAs($promotor);

        $this->getJson("/api/campanhas-auditoria/disponiveis?ponto_venda_uuid={$pdv->uuid}")
            ->assertOk()
            ->assertJsonPath('produtos.0.secao_uuid', $secao->uuid)
            ->assertJsonPath('produtos.0.secao_descricao', $secao->descricao);
    }
}

<?php

namespace Tests\Feature;

use App\Enums\Permissao;
use App\Models\Empresa;
use App\Models\Pedido;
use App\Models\Perfil;
use App\Models\PontoVenda;
use App\Models\ProdutoAuditoria;
use App\Models\TipoRegistro;
use App\Models\Usuario;
use App\Models\Visita;
use App\Models\VisitaRegistro;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Pedidos do ERP (gravados por um integrador externo) e o histórico da loja que o promotor
 * consulta — docs/28-RELATORIOS-FEEDBACK-HISTORICO.md §4.
 */
class PedidoTest extends TestCase
{
    use RefreshDatabase;

    private Empresa $empresa;

    private PontoVenda $pdv;

    protected function setUp(): void
    {
        parent::setUp();
        $this->empresa = Empresa::factory()->create();
        $this->pdv = PontoVenda::factory()->create(['empresa_id' => $this->empresa->id]);
    }

    private function integrador(): void
    {
        Sanctum::actingAs(Usuario::factory()->admin()->create(['empresa_id' => $this->empresa->id]));
    }

    private function payload(array $extra = []): array
    {
        return [
            'ponto_venda_uuid' => $this->pdv->uuid,
            'numero_pedido' => 'PED-100',
            'numero_nf' => null,
            'data_pedido' => '2026-09-10',
            'itens' => [
                ['codigo_externo_produto' => 'ERP-1', 'descricao_produto' => 'Amaciante ERP', 'quantidade' => 12],
                ['codigo_externo_produto' => 'ERP-404', 'descricao_produto' => 'Produto sem cadastro', 'quantidade' => 3.5],
            ],
            ...$extra,
        ];
    }

    public function test_integrador_cria_pedido_e_resolve_produto_pelo_codigo_externo(): void
    {
        $produto = ProdutoAuditoria::factory()->create([
            'empresa_id' => $this->empresa->id, 'descricao' => 'Amaciante Catálogo', 'codigo_externo' => 'ERP-1',
        ]);
        $this->integrador();

        $r = $this->postJson('/api/pedidos', $this->payload())->assertCreated();

        $r->assertJsonPath('pedido.status', 'PENDENTE')
            ->assertJsonCount(2, 'pedido.itens')
            // Casou com o catálogo: usa a descrição do catálogo e expõe o vínculo.
            ->assertJsonPath('pedido.itens.0.descricao_produto', 'Amaciante Catálogo')
            ->assertJsonPath('pedido.itens.0.produto_id', $produto->uuid)
            // Não casou: mantém a descrição crua do ERP, sem vínculo, e o pedido não é rejeitado.
            ->assertJsonPath('pedido.itens.1.descricao_produto', 'Produto sem cadastro')
            ->assertJsonPath('pedido.itens.1.produto_id', null);
    }

    public function test_reenviar_o_mesmo_pedido_atualiza_sem_duplicar_e_preserva_entregas(): void
    {
        $this->integrador();
        $id = $this->postJson('/api/pedidos', $this->payload())->assertCreated()->json('pedido.id');
        $this->postJson("/api/pedidos/{$id}/entregas", ['data_entrega' => '2026-09-12 10:00:00'])->assertCreated();

        $this->postJson('/api/pedidos', $this->payload([
            'numero_nf' => 'NF-9',
            'itens' => [['codigo_externo_produto' => 'ERP-1', 'descricao_produto' => 'Só um item', 'quantidade' => 5]],
        ]))->assertOk()
            ->assertJsonPath('pedido.id', $id)
            ->assertJsonPath('pedido.numero_nf', 'NF-9')
            ->assertJsonCount(1, 'pedido.itens')
            ->assertJsonPath('pedido.status', 'ENTREGUE');

        $this->assertSame(1, Pedido::count());
    }

    public function test_entrega_marca_como_entregue_e_reenvio_nao_duplica(): void
    {
        $this->integrador();
        $id = $this->postJson('/api/pedidos', $this->payload())->json('pedido.id');

        $this->postJson("/api/pedidos/{$id}/entregas", ['data_entrega' => '2026-09-12 10:00:00'])
            ->assertCreated()->assertJsonPath('pedido.status', 'ENTREGUE');
        $this->postJson("/api/pedidos/{$id}/entregas", ['data_entrega' => '2026-09-12 10:00:00'])
            ->assertOk()->assertJsonCount(1, 'pedido.entregas');
        // Segunda entrega (parcial) de outro horário entra normalmente.
        $this->postJson("/api/pedidos/{$id}/entregas", ['data_entrega' => '2026-09-14 09:00:00'])
            ->assertCreated()->assertJsonCount(2, 'pedido.entregas');
    }

    public function test_validacao_e_isolamento_por_empresa(): void
    {
        $this->integrador();
        $this->postJson('/api/pedidos', $this->payload(['itens' => []]))->assertUnprocessable();
        $this->postJson('/api/pedidos', $this->payload(['itens' => [['codigo_externo_produto' => 'X', 'descricao_produto' => 'Y', 'quantidade' => 0]]]))
            ->assertUnprocessable();

        $outraLoja = PontoVenda::factory()->create(['empresa_id' => Empresa::factory()->create()->id]);
        $this->postJson('/api/pedidos', $this->payload(['ponto_venda_uuid' => $outraLoja->uuid]))
            ->assertUnprocessable()->assertJsonValidationErrors('ponto_venda_uuid');
    }

    public function test_promotor_nao_grava_pedido_mas_consulta_os_da_loja(): void
    {
        $this->integrador();
        $this->postJson('/api/pedidos', $this->payload())->assertCreated();
        $this->postJson('/api/pedidos', $this->payload(['numero_pedido' => 'PED-101', 'data_pedido' => '2026-09-15']))->assertCreated();

        Sanctum::actingAs(Usuario::factory()->promotor()->create(['empresa_id' => $this->empresa->id]));

        $this->postJson('/api/pedidos', $this->payload(['numero_pedido' => 'PED-X']))->assertForbidden();

        $this->getJson("/api/pontos-venda/{$this->pdv->uuid}/pedidos")
            ->assertOk()
            ->assertJsonCount(2, 'pedidos')
            // Mais recente primeiro.
            ->assertJsonPath('pedidos.0.numero_pedido', 'PED-101')
            ->assertJsonMissingPath('pedidos.0.itens.0.preco');
    }

    public function test_gestor_precisa_da_permissao_pedidos_gerenciar(): void
    {
        $gestor = Usuario::factory()->gestor()->create(['empresa_id' => $this->empresa->id]);
        Sanctum::actingAs($gestor);
        $this->postJson('/api/pedidos', $this->payload())->assertForbidden();

        $perfil = Perfil::factory()->comPermissoes([Permissao::PEDIDOS_GERENCIAR->value])->create(['empresa_id' => $this->empresa->id]);
        $gestor->update(['perfil_id' => $perfil->id]);
        Sanctum::actingAs($gestor->fresh());
        $this->postJson('/api/pedidos', $this->payload())->assertCreated();
    }

    public function test_pedido_de_outra_empresa_nao_aparece_nem_recebe_entrega(): void
    {
        $outra = Empresa::factory()->create();
        $pdvOutra = PontoVenda::factory()->create(['empresa_id' => $outra->id]);
        $alheio = Pedido::create([
            'empresa_id' => $outra->id, 'ponto_venda_id' => $pdvOutra->id, 'numero_pedido' => 'PED-100', 'data_pedido' => '2026-09-01',
        ]);
        $this->integrador();

        // Mesmo número em empresas diferentes não colide, e o pedido alheio não é tocado.
        $this->postJson('/api/pedidos', $this->payload())->assertCreated();
        $this->postJson("/api/pedidos/{$alheio->uuid}/entregas", ['data_entrega' => '2026-09-12 10:00:00'])->assertNotFound();
        $this->assertSame(2, Pedido::withoutGlobalScopes()->count());
    }

    // ---- histórico da loja (§4.1) ----

    public function test_historico_traz_rupturas_alertas_e_visitas_da_loja_sem_cancelados(): void
    {
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $this->empresa->id, 'nome' => 'Ana']);
        $produto = ProdutoAuditoria::factory()->create(['empresa_id' => $this->empresa->id, 'descricao' => 'Sabão Y']);
        $tipoRuptura = TipoRegistro::create(['empresa_id' => $this->empresa->id, 'descricao' => 'Ruptura', 'eh_ruptura' => true]);
        $tipoAlerta = TipoRegistro::create(['empresa_id' => $this->empresa->id, 'descricao' => 'Alerta', 'eh_alerta' => true]);

        $visita = Visita::factory()->finalizada()->create([
            'empresa_id' => $this->empresa->id, 'usuario_id' => $promotor->id, 'ponto_venda_id' => $this->pdv->id,
        ]);
        VisitaRegistro::create(['visita_id' => $visita->id, 'tipo_registro_id' => $tipoRuptura->id, 'ruptura' => true, 'produto_auditoria_id' => $produto->id]);
        VisitaRegistro::create(['visita_id' => $visita->id, 'tipo_registro_id' => $tipoRuptura->id, 'ruptura' => true, 'cancelado_em' => now()]);
        VisitaRegistro::create(['visita_id' => $visita->id, 'tipo_registro_id' => $tipoAlerta->id, 'observacao' => 'Validade curta']);

        // Outra loja não vaza.
        $outraLoja = PontoVenda::factory()->create(['empresa_id' => $this->empresa->id]);
        $visitaOutra = Visita::factory()->create(['empresa_id' => $this->empresa->id, 'usuario_id' => $promotor->id, 'ponto_venda_id' => $outraLoja->id]);
        VisitaRegistro::create(['visita_id' => $visitaOutra->id, 'tipo_registro_id' => $tipoRuptura->id, 'ruptura' => true]);

        Sanctum::actingAs($promotor);
        $this->getJson("/api/pontos-venda/{$this->pdv->uuid}/historico")
            ->assertOk()
            ->assertJsonCount(1, 'historico.rupturas')
            ->assertJsonPath('historico.rupturas.0.produto', 'Sabão Y')
            ->assertJsonPath('historico.rupturas.0.promotor', 'Ana')
            ->assertJsonCount(1, 'historico.alertas')
            ->assertJsonPath('historico.alertas.0.observacao', 'Validade curta')
            ->assertJsonPath('historico.alertas.0.resolvido', false)
            ->assertJsonCount(1, 'historico.visitas');
    }
}

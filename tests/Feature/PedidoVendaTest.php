<?php

namespace Tests\Feature;

use App\Enums\Permissao;
use App\Models\Empresa;
use App\Models\Parametro;
use App\Models\Perfil;
use App\Models\PontoVenda;
use App\Models\ProdutoAuditoria;
use App\Models\Usuario;
use App\Models\Visita;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * docs/38-PEDIDO-VENDEDOR.md — v1: promotor em "modo Vendedor" monta pedido com preço, item
 * abaixo do mínimo exige autorização de outro usuário, histórico append-only, sem ERP.
 */
class PedidoVendaTest extends TestCase
{
    use RefreshDatabase;

    private Empresa $empresa;

    private Usuario $admin;

    private Usuario $vendedor;

    private PontoVenda $pdv;

    private ProdutoAuditoria $produto;

    private ProdutoAuditoria $produto2;

    protected function setUp(): void
    {
        parent::setUp();

        $this->empresa = Empresa::factory()->create(['pedidos_venda_habilitado' => true]);
        $this->admin = Usuario::factory()->admin()->create(['empresa_id' => $this->empresa->id]);
        $this->vendedor = $this->usuarioCom('promotor', [Permissao::PEDIDOS_VENDA_CRIAR, Permissao::PEDIDOS_VENDA_VISUALIZAR]);
        $this->pdv = PontoVenda::factory()->create(['empresa_id' => $this->empresa->id]);
        // Tabela 10,00 com até 10% de desconto → mínimo 9,00.
        $this->produto = ProdutoAuditoria::factory()->create([
            'empresa_id' => $this->empresa->id, 'preco_tabela' => 10, 'desconto_maximo_pct' => 10,
        ]);
        $this->produto2 = ProdutoAuditoria::factory()->create([
            'empresa_id' => $this->empresa->id, 'preco_tabela' => 5, 'desconto_maximo_pct' => null,
        ]);
        // A maioria dos testes exercita a máquina de estados, não a origem do pedido — libera
        // pedido fora de visita aqui; os testes de PEDIDO_VENDA_SEM_VISITA_PERMITIDO desligam.
        $this->parametroSemVisita = Parametro::create([
            'empresa_id' => $this->empresa->id, 'chave' => 'PEDIDO_VENDA_SEM_VISITA_PERMITIDO', 'valor' => 'true',
        ]);
    }

    private Parametro $parametroSemVisita;

    private function checkin(PontoVenda $pdv): string
    {
        return $this->postJson('/api/visitas', [
            'ponto_venda_uuid' => $pdv->uuid, 'latitude' => $pdv->latitude, 'longitude' => $pdv->longitude,
        ])->assertCreated()->json('visita.id');
    }

    /** @param  list<Permissao>  $permissoes */
    private function usuarioCom(string $tipo, array $permissoes): Usuario
    {
        $perfil = Perfil::factory()->comPermissoes(array_map(fn (Permissao $p) => $p->value, $permissoes))
            ->create(['empresa_id' => $this->empresa->id]);

        return Usuario::factory()->{$tipo}()->create(['empresa_id' => $this->empresa->id, 'perfil_id' => $perfil->id]);
    }

    private function payload(float $preco = 10, float $quantidade = 2): array
    {
        return [
            'ponto_venda_uuid' => $this->pdv->uuid,
            'observacao' => 'Entregar na segunda',
            'itens' => [
                ['produto_uuid' => $this->produto->uuid, 'quantidade' => $quantidade, 'preco' => $preco],
                ['produto_uuid' => $this->produto2->uuid, 'quantidade' => 1, 'preco' => 5],
            ],
        ];
    }

    private function criarPedido(float $preco = 10): array
    {
        Sanctum::actingAs($this->vendedor);

        return $this->postJson('/api/pedidos-venda', $this->payload($preco))->assertCreated()->json('pedido_venda');
    }

    public function test_vendedor_cria_pedido_com_snapshot_de_preco_e_total(): void
    {
        Sanctum::actingAs($this->vendedor);

        $this->postJson('/api/pedidos-venda', $this->payload(9.5, 3))
            ->assertCreated()
            ->assertJsonPath('pedido_venda.status', 'RASCUNHO')
            ->assertJsonPath('pedido_venda.vendedor.id', $this->vendedor->uuid)
            ->assertJsonPath('pedido_venda.itens.0.preco_tabela', 10)
            ->assertJsonPath('pedido_venda.itens.0.preco_minimo', 9)
            ->assertJsonPath('pedido_venda.itens.0.subtotal', 28.5)
            ->assertJsonPath('pedido_venda.itens.0.requer_autorizacao', false)
            ->assertJsonPath('pedido_venda.total', 33.5)
            ->assertJsonPath('pedido_venda.requer_autorizacao', false)
            ->assertJsonPath('pedido_venda.historico.0.acao', 'CRIADO')
            ->assertJsonPath('permissoes.enviar', true)
            ->assertJsonPath('permissoes.aprovar', false);
    }

    public function test_promotor_sem_permissao_nao_cria_nem_lista(): void
    {
        Sanctum::actingAs(Usuario::factory()->promotor()->create(['empresa_id' => $this->empresa->id]));

        $this->postJson('/api/pedidos-venda', $this->payload())->assertForbidden();
        $this->getJson('/api/pedidos-venda')->assertForbidden();
    }

    public function test_produto_sem_preco_nao_entra_no_pedido(): void
    {
        $semPreco = ProdutoAuditoria::factory()->create(['empresa_id' => $this->empresa->id]);
        Sanctum::actingAs($this->vendedor);

        $this->postJson('/api/pedidos-venda', [
            'ponto_venda_uuid' => $this->pdv->uuid,
            'itens' => [['produto_uuid' => $semPreco->uuid, 'quantidade' => 1, 'preco' => 3]],
        ])->assertUnprocessable()->assertJsonValidationErrors('itens.0.produto_uuid');
    }

    public function test_sem_item_abaixo_do_minimo_envio_aprova_direto(): void
    {
        $pedido = $this->criarPedido(9);

        $this->postJson("/api/pedidos-venda/{$pedido['id']}/enviar")
            ->assertOk()
            ->assertJsonPath('pedido_venda.status', 'APROVADO')
            ->assertJsonPath('pedido_venda.historico.0.acao', 'APROVADO')
            ->assertJsonPath('permissoes.concluir', true);
    }

    public function test_item_abaixo_do_minimo_exige_autorizacao_e_trava_edicao(): void
    {
        $pedido = $this->criarPedido(8.5);
        $this->assertTrue($pedido['requer_autorizacao']);

        $this->postJson("/api/pedidos-venda/{$pedido['id']}/enviar")
            ->assertOk()
            ->assertJsonPath('pedido_venda.status', 'PENDENTE_AUTORIZACAO')
            ->assertJsonPath('pedido_venda.historico.0.acao', 'AUTORIZACAO_SOLICITADA')
            ->assertJsonPath('pedido_venda.historico.0.snapshot.itens.0.preco', 8.5)
            ->assertJsonPath('permissoes.editar', false);

        $this->putJson("/api/pedidos-venda/{$pedido['id']}", ['itens' => $this->payload()['itens']])
            ->assertUnprocessable()->assertJsonValidationErrors('status');
    }

    public function test_vendedor_nunca_aprova_o_proprio_pedido_mesmo_com_permissao(): void
    {
        $gestor = $this->usuarioCom('gestor', [Permissao::PEDIDOS_VENDA_CRIAR, Permissao::PEDIDOS_VENDA_APROVAR]);
        Sanctum::actingAs($gestor);
        $pedido = $this->postJson('/api/pedidos-venda', $this->payload(8))->assertCreated()->json('pedido_venda');
        $this->postJson("/api/pedidos-venda/{$pedido['id']}/enviar")->assertOk();

        $this->postJson("/api/pedidos-venda/{$pedido['id']}/aprovar")->assertForbidden();
    }

    public function test_gestor_aprova_e_depois_pedido_e_concluido(): void
    {
        $pedido = $this->criarPedido(8);
        $this->postJson("/api/pedidos-venda/{$pedido['id']}/enviar")->assertOk();

        $gestor = $this->usuarioCom('gestor', [Permissao::PEDIDOS_VENDA_APROVAR]);
        Sanctum::actingAs($gestor);

        $this->getJson('/api/pedidos-venda')
            ->assertOk()
            ->assertJsonPath('resumo.pendentes_autorizacao', 1)
            ->assertJsonPath('pedidos_venda.0.id', $pedido['id']);

        $this->postJson("/api/pedidos-venda/{$pedido['id']}/aprovar", ['motivo' => 'Cliente estratégico'])
            ->assertOk()
            ->assertJsonPath('pedido_venda.status', 'APROVADO')
            ->assertJsonPath('pedido_venda.historico.0.motivo', 'Cliente estratégico')
            ->assertJsonPath('pedido_venda.historico.0.snapshot.itens.0.preco_minimo', 9);

        Sanctum::actingAs($this->vendedor);
        $this->postJson("/api/pedidos-venda/{$pedido['id']}/concluir")
            ->assertOk()
            ->assertJsonPath('pedido_venda.status', 'CONCLUIDO')
            ->assertJsonPath('pedido_venda.concluido_por.id', $this->vendedor->uuid);

        // Terminal — corrigir depois de concluído é um pedido novo.
        $this->postJson("/api/pedidos-venda/{$pedido['id']}/cancelar")->assertUnprocessable();
    }

    public function test_rejeicao_exige_motivo_e_volta_para_rascunho(): void
    {
        $pedido = $this->criarPedido(8);
        $this->postJson("/api/pedidos-venda/{$pedido['id']}/enviar")->assertOk();

        Sanctum::actingAs($this->admin);
        $this->postJson("/api/pedidos-venda/{$pedido['id']}/rejeitar")
            ->assertUnprocessable()->assertJsonValidationErrors('motivo');

        $this->postJson("/api/pedidos-venda/{$pedido['id']}/rejeitar", ['motivo' => 'Desconto alto demais'])
            ->assertOk()
            ->assertJsonPath('pedido_venda.status', 'RASCUNHO')
            ->assertJsonPath('pedido_venda.historico.0.acao', 'REJEITADO')
            ->assertJsonPath('pedido_venda.historico.0.motivo', 'Desconto alto demais');
    }

    public function test_editar_pedido_aprovado_invalida_a_aprovacao(): void
    {
        $pedido = $this->criarPedido(8);
        $this->postJson("/api/pedidos-venda/{$pedido['id']}/enviar")->assertOk();
        Sanctum::actingAs($this->admin);
        $this->postJson("/api/pedidos-venda/{$pedido['id']}/aprovar")->assertOk();

        // Gestor/admin corrige a quantidade de um pedido já aprovado (caso 3 do §3).
        $this->putJson("/api/pedidos-venda/{$pedido['id']}", ['itens' => $this->payload(8, 5)['itens']])
            ->assertOk()
            ->assertJsonPath('pedido_venda.status', 'RASCUNHO')
            ->assertJsonPath('pedido_venda.historico.0.acao', 'ITEM_ALTERADO')
            ->assertJsonPath('pedido_venda.itens.0.quantidade', 5);

        // Ainda abaixo do mínimo — precisa de autorização de novo.
        Sanctum::actingAs($this->vendedor);
        $this->postJson("/api/pedidos-venda/{$pedido['id']}/enviar")
            ->assertOk()->assertJsonPath('pedido_venda.status', 'PENDENTE_AUTORIZACAO');
    }

    public function test_envio_revalida_contra_o_preco_atual_do_catalogo(): void
    {
        $pedido = $this->criarPedido(9);
        $this->assertFalse($pedido['requer_autorizacao']);

        // Tabela subiu depois da digitação: 9,00 agora fica abaixo do mínimo (12 × 0,9 = 10,80).
        $this->produto->update(['preco_tabela' => 12]);

        $this->postJson("/api/pedidos-venda/{$pedido['id']}/enviar")
            ->assertOk()
            ->assertJsonPath('pedido_venda.status', 'PENDENTE_AUTORIZACAO')
            ->assertJsonPath('pedido_venda.itens.0.preco_tabela', 12)
            ->assertJsonPath('pedido_venda.historico.1.acao', 'ITEM_ALTERADO');
    }

    public function test_vendedor_so_ve_os_proprios_pedidos(): void
    {
        $pedido = $this->criarPedido();
        $outro = $this->usuarioCom('promotor', [Permissao::PEDIDOS_VENDA_CRIAR]);
        Sanctum::actingAs($outro);

        $this->getJson('/api/pedidos-venda')->assertOk()->assertJsonCount(0, 'pedidos_venda');
        $this->getJson("/api/pedidos-venda/{$pedido['id']}")->assertNotFound();
    }

    public function test_visita_de_outro_pdv_e_rejeitada(): void
    {
        $outroPdv = PontoVenda::factory()->create(['empresa_id' => $this->empresa->id]);
        Sanctum::actingAs($this->vendedor);
        $visitaUuid = $this->postJson('/api/visitas', [
            'ponto_venda_uuid' => $outroPdv->uuid, 'latitude' => $outroPdv->latitude, 'longitude' => $outroPdv->longitude,
        ])->assertCreated()->json('visita.id');

        $this->postJson('/api/pedidos-venda', [...$this->payload(), 'visita_uuid' => $visitaUuid])
            ->assertUnprocessable()->assertJsonValidationErrors('visita_uuid');

        $payload = [...$this->payload(), 'ponto_venda_uuid' => $outroPdv->uuid, 'visita_uuid' => $visitaUuid];
        $this->postJson('/api/pedidos-venda', $payload)->assertCreated()->assertJsonPath('pedido_venda.visita_id', $visitaUuid);
    }

    public function test_sem_parametro_promotor_so_tira_pedido_dentro_da_visita(): void
    {
        $this->parametroSemVisita->delete();
        Sanctum::actingAs($this->vendedor);

        $this->postJson('/api/pedidos-venda', $this->payload())
            ->assertUnprocessable()->assertJsonValidationErrors('visita_uuid');

        $visitaUuid = $this->checkin($this->pdv);
        $this->postJson('/api/pedidos-venda', [...$this->payload(), 'visita_uuid' => $visitaUuid])
            ->assertCreated()->assertJsonPath('pedido_venda.visita_id', $visitaUuid);
    }

    public function test_parametro_desativado_tambem_exige_visita(): void
    {
        $this->parametroSemVisita->update(['ativo' => false]);
        Sanctum::actingAs($this->vendedor);

        $this->postJson('/api/pedidos-venda', $this->payload())
            ->assertUnprocessable()->assertJsonValidationErrors('visita_uuid');
    }

    public function test_sem_parametro_visita_encerrada_nao_libera_pedido(): void
    {
        $this->parametroSemVisita->update(['valor' => 'false']);
        Sanctum::actingAs($this->vendedor);
        $visitaUuid = $this->checkin($this->pdv);
        Visita::where('uuid', $visitaUuid)->update(['status' => 'FINALIZADA', 'fim_data' => now()]);

        $this->postJson('/api/pedidos-venda', [...$this->payload(), 'visita_uuid' => $visitaUuid])
            ->assertUnprocessable()->assertJsonValidationErrors('visita_uuid');
    }

    public function test_gestor_nao_depende_do_parametro(): void
    {
        $this->parametroSemVisita->delete();
        Sanctum::actingAs($this->usuarioCom('gestor', [Permissao::PEDIDOS_VENDA_CRIAR]));

        $this->postJson('/api/pedidos-venda', $this->payload())->assertCreated();
    }

    public function test_sem_modulo_habilitado_nenhuma_permissao_vale_nem_pra_admin(): void
    {
        $pedido = $this->criarPedido();
        $this->empresa->update(['pedidos_venda_habilitado' => false]);

        // fresh(): numa requisição real o usuário (e a empresa) vêm do banco a cada chamada.
        Sanctum::actingAs($this->vendedor->fresh());
        $this->postJson('/api/pedidos-venda', $this->payload())
            ->assertForbidden()->assertJsonPath('message', fn ($m) => str_contains($m, 'não está habilitado'));
        $this->getJson('/api/pedidos-venda')->assertForbidden();

        Sanctum::actingAs($this->admin->fresh());
        $this->getJson('/api/pedidos-venda')->assertForbidden();
        $this->getJson("/api/pedidos-venda/{$pedido['id']}")->assertForbidden();
    }

    public function test_so_superadmin_habilita_o_modulo(): void
    {
        $this->empresa->update(['pedidos_venda_habilitado' => false]);

        // ADMIN no self-service: campo ignorado (fora do UpdateEmpresaRequest).
        Sanctum::actingAs($this->admin);
        $this->putJson('/api/empresa', ['pedidos_venda_habilitado' => true])->assertOk();
        $this->assertFalse($this->empresa->fresh()->pedidos_venda_habilitado);

        Sanctum::actingAs(Usuario::factory()->create(['user_type' => 'SUPERADMIN', 'empresa_id' => null]));
        $this->putJson("/api/superadmin/empresas/{$this->empresa->uuid}", ['pedidos_venda_habilitado' => true])
            ->assertOk()->assertJsonPath('empresa.pedidos_venda_habilitado', true);
    }

    public function test_auth_me_expoe_permissoes_do_perfil_pro_mobile(): void
    {
        Sanctum::actingAs($this->vendedor);

        $this->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('usuario.perfil.permissoes', ['pedidos_venda.criar', 'pedidos_venda.visualizar']);
    }

    public function test_login_ja_devolve_permissoes_do_perfil(): void
    {
        $this->vendedor->update(['senha_hash' => bcrypt('senha12345')]);

        $this->postJson('/api/auth/login', [
            'email' => $this->vendedor->email,
            'senha' => 'senha12345',
            'dispositivo_identificador' => 'aparelho-teste',
        ])->assertOk()
            ->assertJsonPath('usuario.perfil.permissoes', ['pedidos_venda.criar', 'pedidos_venda.visualizar'])
            ->assertJsonPath('usuario.empresa.pedidos_venda_habilitado', true);
    }

    public function test_preco_de_tabela_e_editavel_pelo_catalogo(): void
    {
        Sanctum::actingAs($this->admin);

        $this->putJson("/api/produtos-auditoria/{$this->produto2->uuid}", ['preco_tabela' => 7.5, 'desconto_maximo_pct' => 5])
            ->assertOk()
            ->assertJsonPath('produto.preco_tabela', 7.5)
            ->assertJsonPath('produto.desconto_maximo_pct', 5);
    }
}

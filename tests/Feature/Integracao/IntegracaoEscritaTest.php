<?php

namespace Tests\Feature\Integracao;

use App\Enums\UserType;
use App\Models\DepartamentoAuditoria;
use App\Models\Empresa;
use App\Models\Integracao;
use App\Models\MarcaAuditoria;
use App\Models\Pedido;
use App\Models\PontoVenda;
use App\Models\ProdutoAuditoria;
use App\Models\RedeLoja;
use App\Models\SecaoAuditoria;
use App\Models\SortimentoPontoVenda;
use App\Models\Supervisor;
use App\Models\Usuario;
use App\Models\Vendedor;
use App\Support\TokenIntegracao;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * docs/96 §7.2 — escrita do ERP pela API de integração: upsert em lote por código, resultado item
 * a item, simulação, idempotência e as mesmas regras das importações por planilha.
 */
class IntegracaoEscritaTest extends TestCase
{
    use RefreshDatabase;

    private Empresa $empresa;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->empresa = Empresa::factory()->create();
        $this->token = $this->tokenCom(array_map(fn ($e) => $e->value, array_filter(\App\Enums\EscopoIntegracao::cases(), fn ($e) => $e->disponivel())));
    }

    private function tokenCom(array $escopos): string
    {
        $integracao = Integracao::create(['empresa_id' => $this->empresa->id, 'nome' => 'ERP', 'escopos' => $escopos]);

        return TokenIntegracao::emitir($integracao, null);
    }

    private function enviar(string $rota, array $corpo, array $headers = [], ?string $token = null): TestResponse
    {
        $this->app['auth']->forgetGuards();
        $this->app['auth']->setDefaultDriver('web');

        return $this->withHeaders(['Authorization' => 'Bearer '.($token ?? $this->token)] + $headers)->putJson("/api/integracao/v1{$rota}", $corpo);
    }

    private function loja(array $extra = []): array
    {
        return $extra + [
            'codigo_externo' => 'L1', 'razao_social' => 'Mercado Um LTDA', 'fantasia' => 'Mercado Um',
            'endereco' => 'Rua A, 1', 'cidade' => 'Manaus', 'latitude' => -3.1, 'longitude' => -60.02,
        ];
    }

    public function test_lojas_upsert_com_resultado_item_a_item_e_item_ruim_nao_derruba_o_lote(): void
    {
        PontoVenda::factory()->create(['empresa_id' => $this->empresa->id, 'codigo_externo' => 'L2', 'fantasia' => 'Antiga']);

        $this->enviar('/lojas', ['items' => [
            $this->loja(),
            ['codigo_externo' => 'L2', 'fantasia' => 'Nova', 'ativo' => false],
            ['codigo_externo' => 'L3', 'fantasia' => 'Sem endereço'],
            ['codigo_externo' => 'L4', 'campo_que_nao_existe' => 1],
        ]])
            ->assertOk()
            ->assertJsonPath('created', 1)
            ->assertJsonPath('updated', 1)
            ->assertJsonPath('errors', 2)
            ->assertJsonPath('results.0.result', 'created')
            ->assertJsonPath('results.1.result', 'updated')
            ->assertJsonPath('results.2.result', 'error')
            ->assertJsonPath('results.3.message', 'Campo(s) desconhecido(s): campo_que_nao_existe.');

        $this->assertDatabaseHas('pontos_venda', ['empresa_id' => $this->empresa->id, 'codigo_externo' => 'L1', 'fantasia' => 'Mercado Um']);
        $this->assertDatabaseHas('pontos_venda', ['codigo_externo' => 'L2', 'fantasia' => 'Nova', 'ativo' => false]);
        $this->assertDatabaseMissing('pontos_venda', ['codigo_externo' => 'L3']);
    }

    public function test_simular_valida_sem_gravar(): void
    {
        $this->enviar('/lojas?dry_run=1', ['items' => [$this->loja()]])
            ->assertOk()
            ->assertJsonPath('dry_run', true)
            ->assertJsonPath('created', 1);

        $this->assertDatabaseMissing('pontos_venda', ['codigo_externo' => 'L1']);
    }

    public function test_limite_do_plano_recusa_o_lote_inteiro(): void
    {
        $this->empresa->update(['limite_pontos_venda' => 1]);

        $this->enviar('/lojas', ['items' => [$this->loja(), $this->loja(['codigo_externo' => 'L9'])]])
            ->assertUnprocessable()
            ->assertHeader('Content-Type', 'application/problem+json');
        $this->assertDatabaseCount('pontos_venda', 0);
    }

    public function test_escrever_nao_implica_ler_e_sem_escopo_da_403(): void
    {
        $soLeitura = $this->tokenCom(['lojas:ler']);
        $this->enviar('/lojas', ['items' => [$this->loja()]], [], $soLeitura)->assertForbidden()->assertJsonPath('detail', 'Este token não tem a permissão lojas:escrever.');

        $soEscrita = $this->tokenCom(['lojas:escrever']);
        $this->app['auth']->forgetGuards();
        $this->app['auth']->setDefaultDriver('web');
        $this->withHeaders(['Authorization' => "Bearer {$soEscrita}"])->getJson('/api/integracao/v1/lojas')->assertForbidden();
    }

    public function test_idempotency_key_devolve_a_mesma_resposta_sem_gravar_de_novo(): void
    {
        $primeira = $this->enviar('/redes', ['items' => [['descricao' => 'Atacadão']]], ['Idempotency-Key' => 'lote-1'])->assertOk();
        RedeLoja::where('descricao', 'Atacadão')->delete();

        $this->enviar('/redes', ['items' => [['descricao' => 'Atacadão']]], ['Idempotency-Key' => 'lote-1'])
            ->assertOk()
            ->assertHeader('Idempotency-Replayed', 'true')
            ->assertExactJson($primeira->json());
        $this->assertDatabaseMissing('redes_lojas', ['descricao' => 'Atacadão']);

        $this->enviar('/redes', ['items' => [['descricao' => 'Outra']]], ['Idempotency-Key' => 'lote-1'])->assertUnprocessable();
    }

    public function test_cadastros_de_apoio_casam_pelo_nome_e_alimentam_lojas_e_produtos(): void
    {
        $this->enviar('/redes', ['items' => [['descricao' => 'Atacadão'], ['descricao' => ' atacadão ']]])->assertJsonPath('created', 1)->assertJsonPath('errors', 1);
        $this->enviar('/ramos-atividade', ['items' => [['descricao' => 'Supermercado']]])->assertJsonPath('created', 1);
        $this->enviar('/departamentos', ['items' => [['descricao' => 'Bebidas']]])->assertJsonPath('created', 1);
        $this->enviar('/secoes', ['items' => [['descricao' => 'Refrigerantes', 'departamento' => 'bebidas'], ['descricao' => 'X', 'departamento' => 'Inexistente']]])
            ->assertJsonPath('created', 1)->assertJsonPath('errors', 1);
        $this->enviar('/marcas', ['items' => [['descricao' => 'Marca Própria', 'propriedade' => 'propria'], ['descricao' => 'Rival', 'propriedade' => 'concorrente']]])->assertJsonPath('created', 2);
        $this->enviar('/redes', ['items' => [['descricao' => 'ATACADÃO', 'ativo' => false]]])->assertJsonPath('updated', 1);

        $this->assertDatabaseHas('redes_lojas', ['empresa_id' => $this->empresa->id, 'descricao' => 'Atacadão', 'ativo' => false]);
        $this->assertSame(1, SecaoAuditoria::withoutGlobalScopes()->where('departamento_id', DepartamentoAuditoria::withoutGlobalScopes()->value('id'))->count());
        $this->assertSame(2, MarcaAuditoria::withoutGlobalScopes()->where('empresa_id', $this->empresa->id)->count());

        $this->enviar('/lojas', ['items' => [$this->loja(['rede' => 'atacadão', 'ramo_atividade' => 'Supermercado'])]])->assertJsonPath('created', 1);
        $this->assertNotNull(PontoVenda::withoutGlobalScopes()->where('codigo_externo', 'L1')->value('rede_loja_id'));

        $this->enviar('/produtos', ['items' => [['codigo_externo' => 'P1', 'descricao' => 'Refri 2L', 'departamento' => 'Bebidas', 'secao' => 'Refrigerantes', 'marca' => 'Marca Própria', 'preco_tabela' => 9.9]]])
            ->assertJsonPath('created', 1);
        $this->assertDatabaseHas('produtos_auditoria', ['codigo_externo' => 'P1', 'descricao' => 'Refri 2L']);
    }

    public function test_mix_aditivo_e_substituir(): void
    {
        $loja = PontoVenda::factory()->create(['empresa_id' => $this->empresa->id, 'codigo_externo' => 'L1']);
        foreach (['P1', 'P2', 'P3'] as $codigo) {
            ProdutoAuditoria::factory()->create(['empresa_id' => $this->empresa->id, 'codigo_externo' => $codigo]);
        }

        $this->enviar('/mix', ['items' => [
            ['codigo_externo_loja' => 'L1', 'codigo_externo_produto' => 'P1'],
            ['codigo_externo_loja' => 'L1', 'codigo_externo_produto' => 'P2'],
            ['codigo_externo_loja' => 'L1', 'codigo_externo_produto' => 'NAO'],
        ]])->assertJsonPath('created', 2)->assertJsonPath('errors', 1);

        $this->enviar('/mix', ['replace' => true, 'items' => [
            ['codigo_externo_loja' => 'L1', 'codigo_externo_produto' => 'P2'],
            ['codigo_externo_loja' => 'L1', 'codigo_externo_produto' => 'P3'],
        ]])->assertJsonPath('created', 1)->assertJsonPath('updated', 1);

        $codigos = SortimentoPontoVenda::where('ponto_venda_id', $loja->id)->with('produto')->get()->pluck('produto.codigo_externo')->sort()->values()->all();
        $this->assertSame(['P2', 'P3'], $codigos);
    }

    public function test_supervisores_vendedores_e_carteira(): void
    {
        PontoVenda::factory()->create(['empresa_id' => $this->empresa->id, 'codigo_externo' => 'L1']);

        $this->enviar('/supervisores', ['items' => [['codigo_externo' => 'S1', 'nome' => 'Sup Um']]])->assertJsonPath('created', 1);
        $this->enviar('/vendedores', ['items' => [
            ['codigo_externo' => '374', 'nome' => 'Diemy', 'codigo_supervisor' => 'S1'],
            ['codigo_externo' => '680', 'nome' => 'Fabiano'],
        ]])->assertJsonPath('created', 2);
        $this->enviar('/carteira', ['items' => [
            ['codigo_externo_loja' => 'L1', 'codigo_externo_vendedor' => '374'],
            ['codigo_externo_loja' => 'L1', 'codigo_externo_vendedor' => '999'],
        ]])->assertJsonPath('created', 1)->assertJsonPath('errors', 1);

        $vendedor = Vendedor::withoutGlobalScopes()->where('codigo_externo', '374')->first();
        $this->assertSame(Supervisor::withoutGlobalScopes()->where('codigo_externo', 'S1')->value('id'), $vendedor->supervisor_id);
        $this->assertSame(['L1'], $vendedor->pontosVenda()->pluck('codigo_externo')->all());

        $this->app['auth']->forgetGuards();
        $this->app['auth']->setDefaultDriver('web');
        $this->withHeaders(['Authorization' => "Bearer {$this->tokenCom(['vendedores:ler'])}"])
            ->getJson('/api/integracao/v1/carteira')->assertOk()->assertJsonPath('data.0.vendedor_codigo_externo', '374');
    }

    public function test_promotores_com_senha_inicial_que_nunca_e_trocada_depois(): void
    {
        Supervisor::create(['empresa_id' => $this->empresa->id, 'codigo_externo' => 'S1', 'nome' => 'Sup']);
        Usuario::factory()->gestor()->create(['empresa_id' => $this->empresa->id, 'codigo_externo' => 'G1']);

        $this->enviar('/promotores', ['items' => [
            ['codigo_externo' => 'PR1', 'nome' => 'Ana', 'email' => 'ANA@cliente.com', 'senha_inicial' => 'provisoria1', 'codigo_supervisor' => 'S1'],
            ['codigo_externo' => 'PR2', 'nome' => 'Sem senha', 'email' => 'b@cliente.com'],
            ['codigo_externo' => 'G1', 'nome' => 'Gestor'],
        ]])->assertJsonPath('created', 1)->assertJsonPath('errors', 2);

        $ana = Usuario::withoutGlobalScopes()->where('codigo_externo', 'PR1')->first();
        $this->assertSame(UserType::PROMOTOR, $ana->user_type);
        $this->assertSame('ana@cliente.com', $ana->email);
        $this->assertTrue(Hash::check('provisoria1', $ana->senha_hash));
        $this->assertTrue($ana->licenca_mobile);
        $this->assertNotNull($ana->supervisor_id);

        $this->enviar('/promotores', ['items' => [['codigo_externo' => 'PR1', 'nome' => 'Ana Paula', 'senha_inicial' => 'outrasenha', 'ativo' => false]]])->assertJsonPath('updated', 1);
        $ana->refresh();
        $this->assertSame('Ana Paula', $ana->nome);
        $this->assertFalse($ana->ativo);
        $this->assertTrue(Hash::check('provisoria1', $ana->senha_hash), 'a senha não pode ser trocada pelo ERP');
    }

    public function test_promotor_sem_licenca_livre_entra_sem_licenca_e_avisa(): void
    {
        $this->empresa->update(['limite_licencas' => 0]);

        $this->enviar('/promotores', ['items' => [['codigo_externo' => 'PR1', 'nome' => 'Ana', 'email' => 'ana@c.com', 'senha_inicial' => 'provisoria1']]])
            ->assertJsonPath('created', 1)
            ->assertJsonPath('details.sem_licenca.0', 'PR1');
        $this->assertFalse(Usuario::withoutGlobalScopes()->where('codigo_externo', 'PR1')->value('licenca_mobile'));
    }

    public function test_pedidos_com_faturamento_entregas_e_leitura(): void
    {
        PontoVenda::factory()->create(['empresa_id' => $this->empresa->id, 'codigo_externo' => 'L1']);
        ProdutoAuditoria::factory()->create(['empresa_id' => $this->empresa->id, 'codigo_externo' => 'P1']);
        $pedido = [
            'numero_pedido' => '1001', 'codigo_externo_loja' => 'L1', 'data_pedido' => '2026-10-20', 'numero_nf' => 'NF-9',
            'valor_total' => 150.5, 'valor_lucro_total' => 30, 'status_faturamento' => 'FATURADO', 'faturado_em' => '2026-10-21',
            'itens' => [['codigo_externo_produto' => 'P1', 'descricao_produto' => 'Refri', 'quantidade' => 10, 'preco_unitario' => 15.05]],
        ];

        $this->enviar('/pedidos', ['items' => [$pedido, ['numero_pedido' => '1002', 'codigo_externo_loja' => 'NAO', 'data_pedido' => '2026-10-20', 'itens' => [['codigo_externo_produto' => 'X', 'descricao_produto' => 'X', 'quantidade' => 1]]]]])
            ->assertOk()->assertJsonPath('created', 1)->assertJsonPath('errors', 1);
        $this->enviar('/pedidos', ['items' => [$pedido]])->assertJsonPath('updated', 1);
        $this->assertSame(1, Pedido::withoutGlobalScopes()->where('numero_pedido', '1001')->count());

        $this->enviar('/entregas', ['items' => [
            ['numero_pedido' => '1001', 'data_entrega' => '2026-10-22T10:00:00Z'],
            ['numero_pedido' => '9999', 'data_entrega' => '2026-10-22T10:00:00Z'],
        ]])->assertJsonPath('created', 1)->assertJsonPath('errors', 1);
        $this->enviar('/entregas', ['items' => [['numero_pedido' => '1001', 'data_entrega' => '2026-10-22T10:00:00Z']]])->assertJsonPath('updated', 1);

        $this->app['auth']->forgetGuards();
        $this->app['auth']->setDefaultDriver('web');
        $this->withHeaders(['Authorization' => "Bearer {$this->tokenCom(['pedidos:ler'])}"])
            ->getJson('/api/integracao/v1/pedidos')
            ->assertOk()
            ->assertJsonPath('data.0.numero_pedido', '1001')
            ->assertJsonPath('data.0.valor_total', 150.5)
            ->assertJsonPath('data.0.status_faturamento', 'FATURADO')
            ->assertJsonCount(1, 'data.0.entregas')
            ->assertJsonPath('data.0.itens.0.codigo_externo_produto', 'P1');
    }

    public function test_lote_acima_do_maximo_e_corpo_sem_itens_dao_422(): void
    {
        $this->enviar('/lojas', [])->assertUnprocessable()->assertJsonPath('status', 422);
        $this->enviar('/redes', ['items' => array_fill(0, 501, ['descricao' => 'x'])])->assertUnprocessable();
    }

    public function test_token_de_outra_empresa_nao_mexe_nos_dados_desta(): void
    {
        $outra = Empresa::factory()->create();
        PontoVenda::factory()->create(['empresa_id' => $outra->id, 'codigo_externo' => 'L1', 'fantasia' => 'Da outra']);

        $this->enviar('/lojas', ['items' => [$this->loja()]])->assertJsonPath('created', 1);

        $this->assertDatabaseHas('pontos_venda', ['empresa_id' => $outra->id, 'codigo_externo' => 'L1', 'fantasia' => 'Da outra']);
        $this->assertDatabaseHas('pontos_venda', ['empresa_id' => $this->empresa->id, 'codigo_externo' => 'L1', 'fantasia' => 'Mercado Um']);
    }
}

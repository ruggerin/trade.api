<?php

namespace Tests\Feature;

use App\Enums\Permissao;
use App\Enums\Propriedade;
use App\Enums\StatusAprovacao;
use App\Enums\TipoItemCampanha;
use App\Models\DepartamentoAuditoria;
use App\Models\Empresa;
use App\Models\MarcaAuditoria;
use App\Models\Parametro;
use App\Models\Perfil;
use App\Models\PontoVenda;
use App\Models\ProdutoAuditoria;
use App\Models\SecaoAuditoria;
use App\Models\SortimentoPontoVenda;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * docs/42-IMPORTACAO-DE-DADOS.md — importação de Produto e de Vínculo Loja × Produto (a de Loja
 * tem os próprios testes em PontoVenda/ImportacaoCsvTest).
 */
class ImportacaoDadosTest extends TestCase
{
    use RefreshDatabase;

    private Empresa $empresa;

    protected function setUp(): void
    {
        parent::setUp();
        $this->empresa = Empresa::factory()->create();
        Sanctum::actingAs(Usuario::factory()->admin()->create(['empresa_id' => $this->empresa->id]));
    }

    private function enviar(string $url, array $linhas, bool $simular = false)
    {
        $arquivo = UploadedFile::fake()->createWithContent('dados.csv', implode("\r\n", $linhas));

        return $this->post($url, ['arquivo' => $arquivo, 'simular' => $simular ? '1' : '0'], ['Accept' => 'application/json']);
    }

    // ---- Produto ----

    public function test_produto_cria_e_atualiza_pelo_codigo_externo_resolvendo_catalogo_por_nome(): void
    {
        $depto = DepartamentoAuditoria::create(['empresa_id' => $this->empresa->id, 'descricao' => 'Congelados']);
        $secao = SecaoAuditoria::create(['empresa_id' => $this->empresa->id, 'departamento_id' => $depto->id, 'descricao' => 'Lasanhas']);
        $marca = MarcaAuditoria::create(['empresa_id' => $this->empresa->id, 'descricao' => 'Seara', 'propriedade' => Propriedade::PROPRIA]);
        $existente = ProdutoAuditoria::factory()->create([
            'empresa_id' => $this->empresa->id, 'codigo_externo' => 'P1', 'descricao' => 'Antigo', 'codigo_barras' => '789',
        ]);

        $this->enviar('/api/produtos-auditoria/importar', [
            'codigo_externo;descricao;codigo_barras;departamento;secao;marca;peso_kg;propriedade;preco_tabela;desconto_maximo_pct;ativo',
            // Existente: só descrição e preço — código de barras não pode sumir.
            'P1;Lasanha Bolonhesa 600g;;;;;;;12,90;;',
            // Novo: seção sem departamento preenche o departamento dela.
            'P2;Lasanha Frango 600g;7891;;lasanhas;SEARA;0,6;Própria;11,50;5;sim',
        ])->assertOk()
            ->assertJsonPath('aplicado', true)
            ->assertJsonPath('criadas', 1)
            ->assertJsonPath('atualizadas', 1);

        $existente->refresh();
        $this->assertSame('Lasanha Bolonhesa 600g', $existente->descricao);
        $this->assertSame('789', $existente->codigo_barras);
        $this->assertEquals(12.9, (float) $existente->preco_tabela);

        $novo = ProdutoAuditoria::where('codigo_externo', 'P2')->firstOrFail();
        $this->assertSame($secao->id, $novo->secao_id);
        $this->assertSame($depto->id, $novo->departamento_id);
        $this->assertSame($marca->id, $novo->marca_id);
        $this->assertEquals(5, (float) $novo->desconto_maximo_pct);
    }

    public function test_produto_erros_apontam_linha_e_nada_e_gravado(): void
    {
        $resposta = $this->enviar('/api/produtos-auditoria/importar', [
            'codigo_externo;descricao;marca;propriedade',
            'P1;Ok;;',
            'P2;;;',
            'P3;X;Marca Nova;',
            'P4;Y;;Talvez',
        ])->assertOk()->assertJsonPath('aplicado', false);

        $erros = collect($resposta->json('erros'))->keyBy('linha');
        $this->assertStringContainsString('descricao é obrigatório', $erros[3]['mensagem']);
        $this->assertStringContainsString('Marca "Marca Nova" não cadastrada', $erros[4]['mensagem']);
        $this->assertStringContainsString('Propriedade "Talvez" inválida', $erros[5]['mensagem']);
        $this->assertSame(0, ProdutoAuditoria::where('empresa_id', $this->empresa->id)->count());
    }

    public function test_produto_respeita_codigo_de_barras_unico(): void
    {
        Parametro::create(['empresa_id' => $this->empresa->id, 'chave' => 'CODIGO_BARRAS_UNICO', 'valor' => 'true']);
        ProdutoAuditoria::factory()->create(['empresa_id' => $this->empresa->id, 'codigo_externo' => 'A', 'codigo_barras' => '123']);

        $this->enviar('/api/produtos-auditoria/importar', ['codigo_externo;descricao;codigo_barras', 'B;Outro;123'])
            ->assertOk()
            ->assertJsonPath('erros.0.mensagem', 'Código de barras 123 já pertence a outro produto (código A).');
    }

    public function test_gestor_sem_catalogo_nao_importa_produto(): void
    {
        $perfil = Perfil::factory()->comPermissoes([Permissao::PONTOS_VENDA_GERENCIAR->value])->create(['empresa_id' => $this->empresa->id]);
        Sanctum::actingAs(Usuario::factory()->gestor()->create(['empresa_id' => $this->empresa->id, 'perfil_id' => $perfil->id]));

        $this->enviar('/api/produtos-auditoria/importar', ['codigo_externo;descricao', 'P1;X'])->assertForbidden();
    }

    // ---- Vínculo Loja × Produto ----

    public function test_vinculo_cria_so_o_que_falta_e_aprova_pendente(): void
    {
        $loja = PontoVenda::factory()->create(['empresa_id' => $this->empresa->id, 'codigo_externo' => 'L1']);
        $p1 = ProdutoAuditoria::factory()->create(['empresa_id' => $this->empresa->id, 'codigo_externo' => 'P1']);
        $p2 = ProdutoAuditoria::factory()->create(['empresa_id' => $this->empresa->id, 'codigo_externo' => 'P2']);
        $p3 = ProdutoAuditoria::factory()->create(['empresa_id' => $this->empresa->id, 'codigo_externo' => 'P3']);
        // Já vinculado, mas pendente (criado por promotor) — a importação aprova.
        $pendente = SortimentoPontoVenda::create([
            'ponto_venda_id' => $loja->id, 'tipo_item' => TipoItemCampanha::PRODUTO, 'produto_id' => $p1->id,
            'status_aprovacao' => StatusAprovacao::PENDENTE,
        ]);
        // Vínculo existente que NÃO vem no arquivo — nunca é removido.
        SortimentoPontoVenda::create(['ponto_venda_id' => $loja->id, 'tipo_item' => TipoItemCampanha::PRODUTO, 'produto_id' => $p3->id]);

        $this->enviar('/api/sortimentos/importar', [
            'codigo_externo_loja;codigo_externo_produto',
            'L1;P1',
            'L1;P2',
            'L1;P2', // repetido no arquivo: conta como já existente, não duplica
        ])->assertOk()
            ->assertJsonPath('aplicado', true)
            ->assertJsonPath('criadas', 1)
            ->assertJsonPath('ja_existentes', 2);

        $this->assertSame(3, SortimentoPontoVenda::where('ponto_venda_id', $loja->id)->count());
        $this->assertNull($pendente->fresh()->status_aprovacao);
        $novo = SortimentoPontoVenda::where('produto_id', $p2->id)->firstOrFail();
        $this->assertNull($novo->status_aprovacao);
        $this->assertNotNull($novo->uuid);
    }

    public function test_vinculo_codigo_inexistente_e_erro_e_simular_nao_grava(): void
    {
        PontoVenda::factory()->create(['empresa_id' => $this->empresa->id, 'codigo_externo' => 'L1']);
        ProdutoAuditoria::factory()->create(['empresa_id' => $this->empresa->id, 'codigo_externo' => 'P1']);

        $this->enviar('/api/sortimentos/importar', ['codigo_externo_loja;codigo_externo_produto', 'L1;P1'], simular: true)
            ->assertOk()->assertJsonPath('aplicado', false)->assertJsonPath('criadas', 1);
        $this->assertSame(0, SortimentoPontoVenda::count());

        $this->enviar('/api/sortimentos/importar', ['codigo_externo_loja;codigo_externo_produto', 'L1;P1', 'L9;P1'])
            ->assertOk()
            ->assertJsonPath('aplicado', false)
            ->assertJsonPath('erros.0.linha', 3)
            ->assertJsonPath('erros.0.mensagem', 'Loja com código "L9" não encontrada.');
        $this->assertSame(0, SortimentoPontoVenda::count());
    }

    public function test_vinculo_exige_as_duas_colunas_no_cabecalho(): void
    {
        $this->enviar('/api/sortimentos/importar', ['codigo_externo_loja', 'L1'])
            ->assertOk()
            ->assertJsonPath('erros.0.mensagem', 'Cabeçalho sem a(s) coluna(s) "codigo_externo_produto" — use o arquivo modelo.');
    }
}

<?php

namespace Tests\Feature;

use App\Enums\StatusAprovacao;
use App\Models\Empresa;
use App\Models\ProdutoAuditoria;
use App\Models\Usuario;
use App\Support\ImagemProduto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Importação de imagens de produtos pelo nome do arquivo — docs/89-IMPORTACAO-DE-IMAGENS-DE-PRODUTOS.md.
 */
class Doc89ImportacaoImagensProdutosTest extends TestCase
{
    use RefreshDatabase;

    private Empresa $empresa;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->empresa = Empresa::factory()->create();
        Sanctum::actingAs(Usuario::factory()->admin()->create(['empresa_id' => $this->empresa->id]));
    }

    private function produto(array $atributos = []): ProdutoAuditoria
    {
        return ProdutoAuditoria::factory()->create(['empresa_id' => $this->empresa->id, 'imagem_url' => null, ...$atributos]);
    }

    // GD não está habilitado neste ambiente — PNG 1x1 real, mesmo truque de PlanogramaTest::fotoFake.
    private function foto(string $nome): UploadedFile
    {
        $caminho = tempnam(sys_get_temp_dir(), 'img').'.png';
        file_put_contents($caminho, base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
        ));

        return new UploadedFile($caminho, $nome, 'image/png', null, true);
    }

    private function simular(string $chave, array $nomes, bool $substituir = false)
    {
        return $this->postJson('/api/produtos-auditoria/imagens/simular', compact('chave', 'nomes', 'substituir'));
    }

    private function importar(string $chave, array $arquivos, bool $substituir = false)
    {
        return $this->post(
            '/api/produtos-auditoria/imagens/importar',
            ['chave' => $chave, 'substituir' => $substituir ? '1' : '0', 'imagens' => $arquivos],
            ['Accept' => 'application/json'],
        );
    }

    public function test_codigo_de_barras_casa_pelos_digitos_e_ignorando_zero_a_esquerda(): void
    {
        $p = $this->produto(['codigo_barras' => '7891000100103']);

        $r = $this->simular('codigo_barras', ['7891000100103.JPG', '07891000100103.jpg', '7891000100103_2.jpg'])->assertOk();

        $r->assertJsonPath('resultados.0.situacao', 'duplicado') // os dois primeiros casam com o mesmo produto
            ->assertJsonPath('resultados.1.situacao', 'duplicado')
            ->assertJsonPath('resultados.0.produto.id', $p->uuid)
            // `_2` vira dígitos 78910001001032: não casa.
            ->assertJsonPath('resultados.2.situacao', 'nao_encontrado');

        $this->simular('codigo_barras', ['07891000100103.jpg'])->assertJsonPath('resultados.0.situacao', 'ok');
    }

    public function test_codigo_de_barras_exato_tem_prioridade_sobre_o_sem_zero(): void
    {
        $exato = $this->produto(['codigo_barras' => '0123']);
        $this->produto(['codigo_barras' => '123']);

        $this->simular('codigo_barras', ['0123.png'])
            ->assertJsonPath('resultados.0.situacao', 'ok')
            ->assertJsonPath('resultados.0.produto.id', $exato->uuid);
    }

    public function test_codigo_externo_sem_diferenciar_maiuscula_e_chave_por_lote(): void
    {
        $p = $this->produto(['codigo_externo' => 'A-3350', 'codigo_barras' => '999']);

        $this->simular('codigo_externo', ['a-3350.webp'])
            ->assertJsonPath('resultados.0.situacao', 'ok')
            ->assertJsonPath('resultados.0.produto.id', $p->uuid);
        // Mesmo produto, mas o arquivo está nomeado pelo código externo e a chave escolhida é a outra.
        $this->simular('codigo_barras', ['A-3350.webp'])->assertJsonPath('resultados.0.situacao', 'nao_encontrado');
        $this->simular('codigo_externo', ['999.jpg'])->assertJsonPath('resultados.0.situacao', 'nao_encontrado');
    }

    public function test_situacoes_da_previa(): void
    {
        $this->produto(['codigo_externo' => 'DUP']);
        $this->produto(['codigo_externo' => 'DUP']);
        $this->produto(['codigo_externo' => 'COMFOTO', 'imagem_url' => 'https://cdn.exemplo.com/x.png']);
        $this->produto(['codigo_externo' => 'INATIVO', 'ativo' => false]);
        $this->produto(['codigo_externo' => 'REJ', 'status_aprovacao' => StatusAprovacao::REJEITADO]);
        ProdutoAuditoria::factory()->create(['empresa_id' => Empresa::factory()->create()->id, 'codigo_externo' => 'OUTRA']);

        $r = $this->simular('codigo_externo', ['DUP.jpg', 'COMFOTO.jpg', 'INATIVO.jpg', 'REJ.jpg', 'OUTRA.jpg', 'foto.gif'])->assertOk();

        $r->assertJsonPath('resultados.0.situacao', 'ambiguo')
            ->assertJsonCount(2, 'resultados.0.produtos')
            ->assertJsonPath('resultados.1.situacao', 'ja_tem_imagem')
            ->assertJsonPath('resultados.2.situacao', 'ok')
            ->assertJsonPath('resultados.2.mensagem', 'Produto inativo.')
            ->assertJsonPath('resultados.3.situacao', 'nao_encontrado')
            ->assertJsonPath('resultados.4.situacao', 'nao_encontrado')
            ->assertJsonPath('resultados.5.situacao', 'formato_invalido')
            ->assertJsonPath('resumo.ok', 1)
            ->assertJsonPath('resumo.nao_encontrado', 2);

        $this->simular('codigo_externo', ['COMFOTO.jpg'], substituir: true)->assertJsonPath('resultados.0.situacao', 'ok');
    }

    public function test_importa_grava_so_o_que_casa_e_reenvio_nao_regrava(): void
    {
        $a = $this->produto(['codigo_barras' => '111']);
        $b = $this->produto(['codigo_barras' => '222']);

        $r = $this->importar('codigo_barras', [$this->foto('111.png'), $this->foto('333.png')])->assertOk();

        $r->assertJsonPath('resultados.0.situacao', 'gravado')
            ->assertJsonPath('resultados.1.situacao', 'nao_encontrado')
            ->assertJsonPath('resumo.gravado', 1);
        $url = $a->fresh()->imagem_url;
        $this->assertNotNull($url);
        Storage::disk('local')->assertExists(ImagemProduto::caminhoPropria($url));
        $this->assertNull($b->fresh()->imagem_url);

        // Reenviar a mesma pasta (queda de conexão): sem "substituir", o que já subiu fica.
        $this->importar('codigo_barras', [$this->foto('111.png'), $this->foto('222.png')])
            ->assertJsonPath('resultados.0.situacao', 'ja_tem_imagem')
            ->assertJsonPath('resultados.1.situacao', 'gravado');
        $this->assertSame($url, $a->fresh()->imagem_url);
    }

    public function test_substituir_troca_e_apaga_o_arquivo_antigo_nosso(): void
    {
        $p = $this->produto(['codigo_externo' => 'P1']);
        $this->importar('codigo_externo', [$this->foto('P1.png')]);
        $antiga = $p->fresh()->imagem_url;

        $this->importar('codigo_externo', [$this->foto('P1.png')], substituir: true)->assertJsonPath('resultados.0.situacao', 'gravado');

        $nova = $p->fresh()->imagem_url;
        $this->assertNotSame($antiga, $nova);
        Storage::disk('local')->assertMissing(ImagemProduto::caminhoPropria($antiga));
        Storage::disk('local')->assertExists(ImagemProduto::caminhoPropria($nova));
    }

    public function test_limites_do_lote(): void
    {
        $this->produto(['codigo_externo' => 'P1']);

        $onze = array_map(fn ($i) => $this->foto("X{$i}.png"), range(1, 11));
        $this->importar('codigo_externo', $onze)->assertUnprocessable()->assertJsonValidationErrors('imagens');

        $grande = UploadedFile::fake()->create('P1.png', 6000, 'image/png');
        $this->importar('codigo_externo', [$grande])->assertUnprocessable();

        $this->simular('codigo_ean', ['P1.png'])->assertUnprocessable()->assertJsonValidationErrors('chave');
    }

    public function test_promotor_nao_importa(): void
    {
        Sanctum::actingAs(Usuario::factory()->promotor()->create(['empresa_id' => $this->empresa->id]));

        $this->simular('codigo_externo', ['P1.png'])->assertForbidden();
        $this->importar('codigo_externo', [$this->foto('P1.png')])->assertForbidden();
    }

    public function test_filtro_sem_imagem_na_lista(): void
    {
        $this->produto(['descricao' => 'Sem foto']);
        $this->produto(['descricao' => 'Com foto', 'imagem_url' => 'https://cdn.exemplo.com/x.png']);

        $this->getJson('/api/produtos-auditoria?sem_imagem=1')
            ->assertOk()
            ->assertJsonCount(1, 'produtos')
            ->assertJsonPath('produtos.0.descricao', 'Sem foto');
    }
}

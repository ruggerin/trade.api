<?php

namespace Tests\Feature\PontoVenda;

use App\Models\Empresa;
use App\Models\PontoVenda;
use App\Models\RedeLoja;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Cadastro de lojas em lote via CSV — codigo_externo é a chave (existe → atualiza, não existe →
 * cria), tudo ou nada, simulação antes de gravar. Ver App\Support\ImportacaoPontosVenda.
 */
class ImportacaoCsvTest extends TestCase
{
    use RefreshDatabase;

    private Empresa $empresa;

    private const CABECALHO = 'codigo_externo;cnpj;razao_social;fantasia;endereco;numero;bairro;cidade;cep;telefone;email;latitude;longitude;rede;ramo_atividade;numero_checkouts;ativo';

    protected function setUp(): void
    {
        parent::setUp();
        $this->empresa = Empresa::factory()->create(['limite_pontos_venda' => null]);
        Sanctum::actingAs(Usuario::factory()->admin()->create(['empresa_id' => $this->empresa->id]));
    }

    private function csv(array $linhas, string $cabecalho = self::CABECALHO): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('lojas.csv', implode("\r\n", [$cabecalho, ...$linhas]));
    }

    private function importar(UploadedFile $arquivo, bool $simular = false)
    {
        return $this->post('/api/pontos-venda/importar', ['arquivo' => $arquivo, 'simular' => $simular ? '1' : '0'], ['Accept' => 'application/json']);
    }

    public function test_cria_lojas_novas_e_atualiza_as_existentes_pelo_codigo_externo(): void
    {
        $rede = RedeLoja::factory()->create(['empresa_id' => $this->empresa->id, 'descricao' => 'Atacadão']);
        $existente = PontoVenda::factory()->create([
            'empresa_id' => $this->empresa->id, 'codigo_externo' => '1001', 'fantasia' => 'Nome Antigo', 'telefone' => '9233330000',
        ]);

        $this->importar($this->csv([
            // Existente: só fantasia e rede preenchidos — o resto (telefone etc.) não pode sumir.
            '1001;;;Nome Novo;;;;;;;;;;atacadão;;;',
            '2002;11.222.333/0001-44;Loja Nova LTDA;Loja Nova;Av. Brasil;100;Centro;Manaus;69000-000;;;-3,1190;-60,0217;Atacadão;;4;sim',
        ]))->assertOk()
            ->assertJsonPath('aplicado', true)
            ->assertJsonPath('criadas', 1)
            ->assertJsonPath('atualizadas', 1)
            ->assertJsonPath('erros', []);

        $existente->refresh();
        $this->assertSame('Nome Novo', $existente->fantasia);
        $this->assertSame('9233330000', $existente->telefone);
        $this->assertSame($rede->id, $existente->rede_loja_id);

        $nova = PontoVenda::where('codigo_externo', '2002')->firstOrFail();
        $this->assertEqualsWithDelta(-3.119, $nova->latitude, 0.0001);
        $this->assertSame(4, $nova->numero_checkouts);
        $this->assertSame(2, PontoVenda::count());

        // Reimportar o mesmo arquivo não duplica nada — tudo vira atualização.
        $this->importar($this->csv(['2002;;;Loja Nova;;;;;;;;;;;;;']))
            ->assertOk()->assertJsonPath('criadas', 0)->assertJsonPath('atualizadas', 1);
        $this->assertSame(2, PontoVenda::count());
    }

    public function test_simular_valida_sem_gravar(): void
    {
        $this->importar($this->csv(['3003;;Razão;Fantasia;Rua X;;;Manaus;;;;-3.1;-60.0;;;;']), simular: true)
            ->assertOk()
            ->assertJsonPath('aplicado', false)
            ->assertJsonPath('criadas', 1);

        $this->assertSame(0, PontoVenda::count());
    }

    public function test_qualquer_erro_nao_grava_nada_e_aponta_a_linha(): void
    {
        $resposta = $this->importar($this->csv([
            '4004;;Razão;Fantasia;Rua X;;;Manaus;;;;-3.1;-60.0;;;;',
            ';;Sem código;Sem código;Rua;;;Manaus;;;;-3.1;-60.0;;;;',
            '5005;;;Só fantasia;;;;;;;;;;;;;',
            '6006;;Razão;Fantasia;Rua;;;Manaus;;;;-3.1;-60.0;Rede Inexistente;;;',
            '4004;;Dup;Dup;Rua;;;Manaus;;;;-3.1;-60.0;;;;',
        ]))->assertOk()->assertJsonPath('aplicado', false);

        $erros = collect($resposta->json('erros'))->keyBy('linha');
        $this->assertStringContainsString('Código externo vazio', $erros[3]['mensagem']);
        $this->assertStringContainsString('razao_social é obrigatório', $erros[4]['mensagem']);
        $this->assertStringContainsString('Rede "Rede Inexistente" não cadastrada', $erros[5]['mensagem']);
        $this->assertStringContainsString('repetido no arquivo (já apareceu na linha 2)', $erros[6]['mensagem']);
        $this->assertSame(0, PontoVenda::count());
    }

    public function test_cnpj_de_outra_loja_e_recusado_mesmo_com_mascara_diferente(): void
    {
        PontoVenda::factory()->create(['empresa_id' => $this->empresa->id, 'codigo_externo' => '7007', 'cnpj' => '11.222.333/0001-44']);

        $this->importar($this->csv(['8008;11222333000144;Razão;Fantasia;Rua;;;Manaus;;;;-3.1;-60.0;;;;']))
            ->assertOk()
            ->assertJsonPath('aplicado', false)
            ->assertJsonPath('erros.0.mensagem', 'CNPJ 11222333000144 já pertence a outra loja (código 7007).');
    }

    public function test_aceita_virgula_windows_1252_e_cabecalho_com_acento(): void
    {
        $conteudo = mb_convert_encoding(
            "Código Externo,Razão Social,Fantasia,Endereço,Cidade,Latitude,Longitude\r\n9009,Padaria São João,São João,Rua Ç,Manaus,-3.1,-60.0\r\n",
            'Windows-1252',
            'UTF-8',
        );

        $this->importar(UploadedFile::fake()->createWithContent('lojas.csv', $conteudo))
            ->assertOk()->assertJsonPath('aplicado', true)->assertJsonPath('criadas', 1);

        $this->assertSame('Padaria São João', PontoVenda::where('codigo_externo', '9009')->value('razao_social'));
    }

    public function test_respeita_o_limite_de_lojas_do_plano(): void
    {
        $this->empresa->update(['limite_pontos_venda' => 1]);

        $this->importar($this->csv([
            'A1;;R;F;Rua;;;Manaus;;;;-3.1;-60.0;;;;',
            'A2;;R;F;Rua;;;Manaus;;;;-3.1;-60.0;;;;',
        ]))->assertOk()
            ->assertJsonPath('aplicado', false)
            ->assertJsonPath('erros.0.mensagem', 'O arquivo cria 2 loja(s) nova(s), mas o plano permite 1 e já existem 0.');
    }

    public function test_coluna_desconhecida_e_recusada(): void
    {
        $this->importar($this->csv(['1;x'], 'codigo_externo;coluna_inventada'))
            ->assertOk()
            ->assertJsonPath('erros.0.mensagem', 'Coluna(s) desconhecida(s): coluna_inventada — use o arquivo modelo.');
    }

    public function test_promotor_nao_importa(): void
    {
        Sanctum::actingAs(Usuario::factory()->promotor()->create(['empresa_id' => $this->empresa->id]));

        $this->importar($this->csv(['1;;R;F;Rua;;;Manaus;;;;-3.1;-60.0;;;;']))->assertForbidden();
    }
}

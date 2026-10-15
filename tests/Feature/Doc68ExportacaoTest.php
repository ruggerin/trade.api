<?php

namespace Tests\Feature;

use App\Enums\Permissao;
use App\Enums\StatusVisita;
use App\Models\Empresa;
use App\Models\Perfil;
use App\Models\PontoVenda;
use App\Models\ProdutoAuditoria;
use App\Models\RedeLoja;
use App\Models\TipoRegistro;
use App\Models\Usuario;
use App\Models\Visita;
use App\Models\VisitaRegistro;
use App\Support\Exportacao\Coluna;
use App\Support\Exportacao\Formatador;
use App\Support\Fuso;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Exportação padronizada (docs/68): o arquivo é o que a tela mostra, com formatação e rastro únicos. */
class Doc68ExportacaoTest extends TestCase
{
    use RefreshDatabase;

    private Empresa $empresa;

    private Usuario $admin;

    private TipoRegistro $ruptura;

    protected function setUp(): void
    {
        parent::setUp();
        $this->empresa = Empresa::factory()->create(['nome_fantasia' => 'Mercados Norte']);
        $this->admin = Usuario::factory()->admin()->create(['empresa_id' => $this->empresa->id, 'nome' => 'Admin Teste']);
        $this->ruptura = TipoRegistro::create(['empresa_id' => $this->empresa->id, 'descricao' => 'Ruptura', 'eh_ruptura' => true, 'eh_alerta' => true]);
        Sanctum::actingAs($this->admin);
    }

    /** Conteúdo do arquivo baixado (BinaryFileResponse fica em disco até ser enviado). */
    private function conteudo(TestResponse $resposta): string
    {
        return file_get_contents($resposta->baseResponse->getFile()->getPathname());
    }

    /** @return list<array<int, string>> linhas do CSV (sem o BOM), já separadas por `;`. */
    private function csv(TestResponse $resposta): array
    {
        $texto = $this->conteudo($resposta);
        $this->assertStringStartsWith("\xEF\xBB\xBF", $texto, 'CSV precisa do BOM UTF-8 pro Excel pt-BR');
        $linhas = array_filter(explode("\r\n", substr($texto, 3)), fn ($l) => $l !== '');

        return array_map(fn ($l) => str_getcsv($l, ';', '"', ''), array_values($linhas));
    }

    private function visitaComRegistros(int $n, array $registro = []): Visita
    {
        $pdv = PontoVenda::factory()->create(['empresa_id' => $this->empresa->id, 'fantasia' => 'Mercado Central']);
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $this->empresa->id, 'nome' => 'Ana']);
        $visita = Visita::factory()->create([
            'empresa_id' => $this->empresa->id, 'usuario_id' => $promotor->id, 'ponto_venda_id' => $pdv->id,
            'status' => StatusVisita::FINALIZADA, 'inicio_data' => now()->subHours(3), 'fim_data' => now()->subHours(2),
        ]);
        for ($i = 0; $i < $n; $i++) {
            VisitaRegistro::create($registro + ['visita_id' => $visita->id, 'tipo_registro_id' => $this->ruptura->id, 'observacao' => "Registro {$i}"]);
        }

        return $visita;
    }

    // ---- Formatador (regras únicas) ----

    public function test_formatador_converte_instante_utc_pro_fuso_da_empresa_e_nao_inventa_zero(): void
    {
        $f = new Formatador('America/Manaus'); // UTC-4

        $this->assertSame('12/10/2026 22:30', $f->texto(new Coluna('c', 'C', 'data_hora'), '2026-10-13T02:30:00.000000Z'));
        $this->assertSame('13/10/2026', $f->texto(new Coluna('c', 'C', 'data'), '2026-10-13'), 'data pura não troca de dia');
        $this->assertSame('', $f->texto(new Coluna('c', 'C', 'moeda'), null), 'sem informação é vazio, nunca zero');
        $this->assertSame('1234,50', $f->texto(new Coluna('c', 'C', 'moeda'), 1234.5));
        $this->assertSame('Sim', $f->texto(new Coluna('c', 'C', 'booleano'), true));
        $this->assertSame('65', $f->texto(new Coluna('c', 'C', 'minutos'), 64.6));
    }

    public function test_formatador_neutraliza_formula_em_texto_de_usuario(): void
    {
        $f = new Formatador('UTC');

        $this->assertSame("'=HYPERLINK(\"http://x\")", $f->texto(new Coluna('c', 'C'), '=HYPERLINK("http://x")'));
        $this->assertSame("'+55 92 99999", $f->texto(new Coluna('c', 'C'), '+55 92 99999'));
        $this->assertSame('Loja normal', $f->texto(new Coluna('c', 'C'), 'Loja normal'));
    }

    // ---- Registros: arquivo = tela ----

    public function test_csv_de_registros_traz_o_que_a_tela_mostra_e_so_da_propria_empresa(): void
    {
        $this->visitaComRegistros(2);
        // Outra empresa: não pode vazar de jeito nenhum.
        $outra = Empresa::factory()->create();
        $tipoOutra = TipoRegistro::create(['empresa_id' => $outra->id, 'descricao' => 'Ruptura', 'eh_alerta' => true]);
        $pdvOutra = PontoVenda::factory()->create(['empresa_id' => $outra->id]);
        $visitaOutra = Visita::factory()->create(['empresa_id' => $outra->id, 'ponto_venda_id' => $pdvOutra->id, 'usuario_id' => Usuario::factory()->promotor()->create(['empresa_id' => $outra->id])->id, 'status' => StatusVisita::FINALIZADA, 'inicio_data' => now()->subHour(), 'fim_data' => now()]);
        VisitaRegistro::create(['visita_id' => $visitaOutra->id, 'tipo_registro_id' => $tipoOutra->id, 'observacao' => 'SEGREDO DA OUTRA']);

        $resposta = $this->postJson('/api/exportacoes', ['rotina' => 'registros', 'formato' => 'csv'])->assertOk();
        $linhas = $this->csv($resposta);

        $this->assertSame(['Data/Hora', 'Tipo', 'Loja', 'Rede', 'Produto', 'Observação', 'Promotor', 'Status', 'Motivo da resolução'], $linhas[0]);
        $this->assertCount(3, $linhas, 'cabeçalho + 2 registros da própria empresa');
        $this->assertStringNotContainsString('SEGREDO DA OUTRA', $this->conteudo($resposta));
        $this->assertContains('Aberto', array_column($linhas, 7));
        $this->assertSame('2', $resposta->headers->get('X-Linhas-Exportadas'));
    }

    public function test_exporta_todas_as_paginas_nao_so_a_primeira(): void
    {
        $this->visitaComRegistros(205); // > 200 por página (e > 15 da tela)

        $linhas = $this->csv($this->postJson('/api/exportacoes', ['rotina' => 'registros', 'formato' => 'csv'])->assertOk());

        $this->assertCount(206, $linhas, 'cabeçalho + os 205 registros, atravessando a paginação');
    }

    public function test_respeita_filtro_da_tela_e_descarta_parametro_que_a_tela_nao_tem(): void
    {
        $this->visitaComRegistros(2, ['ruptura' => true]);
        $this->visitaComRegistros(3, ['ruptura' => false]);

        $resposta = $this->postJson('/api/exportacoes', [
            'rotina' => 'registros', 'formato' => 'csv',
            // `por_pagina` e `empresa_uuid` não são filtros da tela: precisam ser ignorados.
            'filtros' => ['ruptura' => 1, 'por_pagina' => 1, 'empresa_uuid' => 'qualquer'],
        ])->assertOk();

        $this->assertCount(3, $this->csv($resposta), 'cabeçalho + só as 2 rupturas, mesmo com por_pagina=1 no pedido');
    }

    public function test_texto_de_usuario_que_parece_formula_sai_neutralizado(): void
    {
        $this->visitaComRegistros(1, ['observacao' => '=1+1']);
        // observacao é sobrescrita no helper; cria direto:
        VisitaRegistro::query()->update(['observacao' => '=HYPERLINK("http://mal.com")']);

        $conteudo = $this->conteudo($this->postJson('/api/exportacoes', ['rotina' => 'registros', 'formato' => 'csv'])->assertOk());

        $this->assertStringContainsString("'=HYPERLINK", $conteudo);
    }

    // ---- Lojas: texto é texto ----

    public function test_cnpj_com_zero_a_esquerda_continua_texto_no_csv_e_no_xlsx(): void
    {
        $rede = RedeLoja::create(['empresa_id' => $this->empresa->id, 'descricao' => 'Rede Modelo']);
        PontoVenda::factory()->create(['empresa_id' => $this->empresa->id, 'fantasia' => 'Loja Zero', 'cnpj' => '01234567000199', 'rede_loja_id' => $rede->id]);

        $csv = $this->csv($this->postJson('/api/exportacoes', ['rotina' => 'lojas', 'formato' => 'csv'])->assertOk());
        $this->assertContains('01234567000199', array_column($csv, 1));

        $xlsx = $this->conteudo($this->postJson('/api/exportacoes', ['rotina' => 'lojas', 'formato' => 'xlsx'])->assertOk());
        $planilha = $this->lerSheet($xlsx, 'xl/worksheets/sheet1.xml');
        $this->assertStringContainsString('<t xml:space="preserve">01234567000199</t>', $planilha, 'CNPJ vai como string, nunca número');
        $this->assertStringContainsString('Rede Modelo', $planilha);
    }

    // ---- XLSX válido ----

    public function test_xlsx_e_um_pacote_valido_com_aba_dados_e_aba_sobre(): void
    {
        $this->visitaComRegistros(2);

        $resposta = $this->postJson('/api/exportacoes', [
            'rotina' => 'registros', 'formato' => 'xlsx', 'descricao_filtros' => 'Tipo: Ruptura · Status: Aberto',
        ])->assertOk();
        $this->assertSame('mercados-norte-registros-'.now(\App\Support\Fuso::daEmpresa($this->empresa))->toDateString().'.xlsx', $resposta->baseResponse->getFile()->getFilename() === '' ? '' : basename($resposta->headers->get('Content-Disposition') ? $this->nomeDoDownload($resposta) : ''));

        $conteudo = $this->conteudo($resposta);
        $this->assertStringStartsWith('PK', $conteudo, 'assinatura de ZIP');

        $entradas = $this->entradasDoZip($conteudo);
        foreach (['[Content_Types].xml', '_rels/.rels', 'xl/workbook.xml', 'xl/_rels/workbook.xml.rels', 'xl/styles.xml', 'xl/worksheets/sheet1.xml', 'xl/worksheets/sheet2.xml'] as $parte) {
            $this->assertArrayHasKey($parte, $entradas, "faltou {$parte} no pacote");
            $this->assertNotFalse(simplexml_load_string($entradas[$parte]), "{$parte} não é XML válido");
        }

        $dados = $entradas['xl/worksheets/sheet1.xml'];
        $this->assertStringContainsString('<t xml:space="preserve">Data/Hora</t>', $dados);
        $this->assertStringContainsString('<pane ySplit="1"', $dados, 'cabeçalho congelado');
        $this->assertSame(3, substr_count($dados, '<row '), 'cabeçalho + 2 linhas');

        $sobre = $entradas['xl/worksheets/sheet2.xml'];
        $this->assertStringContainsString('Mercados Norte', $sobre);
        $this->assertStringContainsString('Tipo: Ruptura · Status: Aberto', $sobre);
        $this->assertStringContainsString('Admin Teste', $sobre);
    }

    // ---- Tempo na loja: agrupar muda a coluna ----

    public function test_tempo_na_loja_por_dia_exporta_data_formatada(): void
    {
        // Meio-dia UTC fixo: com o relógio real o teste cruzava a meia-noite local e quebrava de madrugada.
        Carbon::setTestNow(Carbon::parse('2026-09-23 15:00:00', 'UTC'));

        $pdv = PontoVenda::factory()->create(['empresa_id' => $this->empresa->id]);
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $this->empresa->id]);
        Visita::factory()->create([
            'empresa_id' => $this->empresa->id, 'usuario_id' => $promotor->id, 'ponto_venda_id' => $pdv->id,
            'status' => StatusVisita::FINALIZADA, 'inicio_data' => now()->subMinutes(70), 'fim_data' => now()->subMinutes(40),
        ]);
        // "Hoje" no fuso da empresa (a tela manda o dia local), não em UTC — senão o teste varia com a hora.
        $hoje = now(Fuso::daEmpresa($this->empresa))->toDateString();

        $linhas = $this->csv($this->postJson('/api/exportacoes', [
            'rotina' => 'tempo-na-loja', 'formato' => 'csv',
            'filtros' => ['agrupar' => 'dia', 'data_inicio' => $hoje, 'data_fim' => $hoje],
        ])->assertOk());

        $this->assertSame('Dia', $linhas[0][0]);
        $this->assertMatchesRegularExpression('#^\d{2}/\d{2}/\d{4}$#', $linhas[1][0]);
        $this->assertSame('30', $linhas[1][2], 'tempo total em minutos, número');

        Carbon::setTestNow();
    }

    // ---- Segurança, limite e rastro ----

    public function test_promotor_nao_exporta(): void
    {
        Sanctum::actingAs(Usuario::factory()->promotor()->create(['empresa_id' => $this->empresa->id]));

        $this->postJson('/api/exportacoes', ['rotina' => 'registros', 'formato' => 'csv'])->assertForbidden();
    }

    public function test_gestor_so_exporta_o_que_a_tela_dele_libera(): void
    {
        $perfil = Perfil::factory()->comPermissoes([Permissao::TELA_REGISTROS->value])->create(['empresa_id' => $this->empresa->id]);
        Sanctum::actingAs(Usuario::factory()->gestor()->create(['empresa_id' => $this->empresa->id, 'perfil_id' => $perfil->id]));

        $this->postJson('/api/exportacoes', ['rotina' => 'registros', 'formato' => 'csv'])->assertOk();
        $this->postJson('/api/exportacoes', ['rotina' => 'lojas', 'formato' => 'csv'])->assertForbidden();
    }

    public function test_rotina_e_formato_invalidos_sao_recusados(): void
    {
        $this->postJson('/api/exportacoes', ['rotina' => 'tabela-secreta', 'formato' => 'csv'])->assertStatus(422)->assertJsonValidationErrors('rotina');
        $this->postJson('/api/exportacoes', ['rotina' => 'registros', 'formato' => 'exe'])->assertStatus(422)->assertJsonValidationErrors('formato');
    }

    public function test_passou_do_teto_recusa_com_mensagem_e_nao_corta_em_silencio(): void
    {
        config(['exportacao.limite_linhas' => 3]);
        $this->visitaComRegistros(5);

        $this->postJson('/api/exportacoes', ['rotina' => 'registros', 'formato' => 'csv'])
            ->assertStatus(422)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'Refine o filtro'));
        $this->assertDatabaseCount('exportacoes', 0);
    }

    public function test_toda_exportacao_deixa_rastro_com_quem_o_que_e_quantas_linhas(): void
    {
        $this->visitaComRegistros(2, ['ruptura' => true]);

        $this->postJson('/api/exportacoes', ['rotina' => 'registros', 'formato' => 'xlsx', 'filtros' => ['ruptura' => 1]])->assertOk();

        $this->assertDatabaseHas('exportacoes', [
            'empresa_id' => $this->empresa->id, 'usuario_id' => $this->admin->id,
            'rotina' => 'registros', 'formato' => 'xlsx', 'linhas' => 2,
        ]);
    }

    // ---- utilitários de leitura do ZIP (o PHP local não tem ext-zip) ----

    private function nomeDoDownload(TestResponse $resposta): string
    {
        preg_match('/filename="?([^";]+)"?/', (string) $resposta->headers->get('Content-Disposition'), $m);

        return $m[1] ?? '';
    }

    private function lerSheet(string $zip, string $parte): string
    {
        return $this->entradasDoZip($zip)[$parte] ?? '';
    }

    /** @return array<string, string> nome → conteúdo descomprimido */
    private function entradasDoZip(string $zip): array
    {
        $saida = [];
        $pos = 0;
        while (substr($zip, $pos, 4) === "PK\x03\x04") {
            $h = unpack('vversao/vflags/vmetodo/vhora/vdata/Vcrc/Vcomp/Vorig/vnome/vextra', substr($zip, $pos + 4, 26));
            $nome = substr($zip, $pos + 30, $h['nome']);
            $dados = substr($zip, $pos + 30 + $h['nome'] + $h['extra'], $h['comp']);
            $saida[$nome] = $h['metodo'] === 8 ? gzinflate($dados) : $dados;
            $this->assertSame($h['crc'], crc32($saida[$nome]), "CRC de {$nome}");
            $pos += 30 + $h['nome'] + $h['extra'] + $h['comp'];
        }

        return $saida;
    }
}

<?php

namespace Tests\Feature;

use App\Models\Empresa;
use App\Models\Parametro;
use App\Models\Usuario;
use App\Support\ParametrosMetadados;
use App\Support\ParametrosPadrao;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Tela de Configurações (docs/62): catálogo com metadados e gravação em lote validada. */
class Doc62ConfiguracoesTest extends TestCase
{
    use RefreshDatabase;

    private Empresa $empresa;

    protected function setUp(): void
    {
        parent::setUp();
        $this->empresa = Empresa::factory()->create();
        Sanctum::actingAs(Usuario::factory()->admin()->create(['empresa_id' => $this->empresa->id]));
    }

    private function item(array $areas, string $chave): array
    {
        return collect($areas)->flatMap(fn ($a) => $a['itens'])->firstWhere('chave', $chave);
    }

    public function test_todo_item_do_catalogo_tem_area_rotulo_e_tipo_validos(): void
    {
        foreach (array_keys(ParametrosPadrao::CATALOGO) as $chave) {
            $this->assertArrayHasKey($chave, ParametrosMetadados::ITENS, "$chave sem metadados na tela de Configurações");
            $meta = ParametrosMetadados::ITENS[$chave];
            $this->assertArrayHasKey($meta['area'], ParametrosMetadados::AREAS, "$chave com área inexistente");
            $this->assertNotSame('', $meta['rotulo']);
            $this->assertContains($meta['tipo'], ['bool', 'numero', 'numero_ou_zero', 'numero_ou_sem_limite', 'lista', 'hora']);
            // O padrão do catálogo precisa passar na própria validação.
            $this->assertNull(
                \App\Support\ConfiguracoesEmpresa::erroDoValor($chave, ParametrosPadrao::CATALOGO[$chave]['valor']),
                "padrão de $chave inválido pro próprio tipo",
            );
        }
    }

    public function test_catalogo_lista_tudo_mesmo_sem_a_linha_cadastrada(): void
    {
        $areas = $this->getJson('/api/parametros/catalogo')->assertOk()->json('areas');

        $total = collect($areas)->sum(fn ($a) => count($a['itens']));
        $this->assertSame(count(ParametrosPadrao::CATALOGO), $total);

        $raio = $this->item($areas, 'CHECKIN_RAIO_METROS');
        $this->assertSame('200', $raio['valor']);
        $this->assertFalse($raio['cadastrado']);
        $this->assertFalse($raio['alterado_do_padrao']);
    }

    public function test_catalogo_mostra_valor_da_empresa_e_marca_alterado(): void
    {
        Parametro::create(['empresa_id' => $this->empresa->id, 'chave' => 'CHECKIN_RAIO_METROS', 'valor' => '150', 'ativo' => true]);
        Parametro::create(['empresa_id' => $this->empresa->id, 'chave' => 'CODIGO_BARRAS_UNICO', 'valor' => 'sim', 'ativo' => true]);

        $areas = $this->getJson('/api/parametros/catalogo')->json('areas');

        $this->assertSame('150', $this->item($areas, 'CHECKIN_RAIO_METROS')['valor']);
        $this->assertTrue($this->item($areas, 'CHECKIN_RAIO_METROS')['alterado_do_padrao']);
        // "sim" legado é lido como ligado e devolvido normalizado.
        $this->assertSame('true', $this->item($areas, 'CODIGO_BARRAS_UNICO')['valor']);
    }

    public function test_raio_desativado_e_sem_limite_e_conta_como_alterado(): void
    {
        Parametro::create(['empresa_id' => $this->empresa->id, 'chave' => 'CHECKIN_RAIO_METROS', 'valor' => '200', 'ativo' => false]);

        $raio = $this->item($this->getJson('/api/parametros/catalogo')->json('areas'), 'CHECKIN_RAIO_METROS');

        $this->assertFalse($raio['ativo']);
        $this->assertTrue($raio['alterado_do_padrao']);
        $this->assertSame('sem_limite', $raio['ativo_significa']);
    }

    public function test_outro_parametro_desativado_volta_ao_padrao_na_tela(): void
    {
        Parametro::create(['empresa_id' => $this->empresa->id, 'chave' => 'ATRASO_TOLERANCIA_MINUTOS', 'valor' => '90', 'ativo' => false]);

        $item = $this->item($this->getJson('/api/parametros/catalogo')->json('areas'), 'ATRASO_TOLERANCIA_MINUTOS');

        $this->assertSame('30', $item['valor']);
        $this->assertFalse($item['alterado_do_padrao']);
    }

    public function test_lote_cria_linha_inexistente_e_atualiza_a_existente(): void
    {
        Parametro::create(['empresa_id' => $this->empresa->id, 'chave' => 'CONTRATO_AVISO_DIAS', 'valor' => '30', 'ativo' => true]);

        $resposta = $this->putJson('/api/parametros/lote', ['alteracoes' => [
            ['chave' => 'CONTRATO_AVISO_DIAS', 'valor' => '45'],
            ['chave' => 'ATRASO_TOLERANCIA_MINUTOS', 'valor' => '15'],
            ['chave' => 'CODIGO_BARRAS_UNICO', 'valor' => true],
            ['chave' => 'JORNADA_INICIO', 'valor' => '08:30'],
        ]])->assertOk();

        $this->assertDatabaseHas('parametros', ['empresa_id' => $this->empresa->id, 'chave' => 'CONTRATO_AVISO_DIAS', 'valor' => '45']);
        $this->assertDatabaseHas('parametros', ['empresa_id' => $this->empresa->id, 'chave' => 'ATRASO_TOLERANCIA_MINUTOS', 'valor' => '15', 'ativo' => true]);
        $this->assertDatabaseHas('parametros', ['empresa_id' => $this->empresa->id, 'chave' => 'CODIGO_BARRAS_UNICO', 'valor' => 'true']);
        $this->assertSame('15', $this->item($resposta->json('areas'), 'ATRASO_TOLERANCIA_MINUTOS')['valor']);
    }

    public function test_lote_e_tudo_ou_nada_quando_um_item_e_invalido(): void
    {
        $this->putJson('/api/parametros/lote', ['alteracoes' => [
            ['chave' => 'CONTRATO_AVISO_DIAS', 'valor' => '45'],
            ['chave' => 'ATRASO_TOLERANCIA_MINUTOS', 'valor' => '9999'],
        ]])->assertStatus(422)->assertJsonValidationErrors(['alteracoes.1.valor']);

        $this->assertDatabaseMissing('parametros', ['chave' => 'CONTRATO_AVISO_DIAS']);
    }

    public function test_valores_invalidos_por_tipo_sao_recusados(): void
    {
        $recusa = fn (string $chave, mixed $valor) => $this->putJson('/api/parametros/lote', ['alteracoes' => [['chave' => $chave, 'valor' => $valor]]])
            ->assertStatus(422);

        $recusa('CODIGO_BARRAS_UNICO', 'talvez');           // bool
        $recusa('CHECKIN_RAIO_METROS', '5');                // abaixo do mínimo
        $recusa('CHECKIN_RAIO_METROS', 'abc');              // não numérico
        $recusa('RASTREAMENTO_EXIGENCIA', 'OUTRA');         // fora das opções
        $recusa('JORNADA_FIM', '25:99');                    // hora
        $recusa('RASTREAMENTO_INTERVALO_SEGUNDOS', '10');   // abaixo do piso do app (0 desliga, ≥30 liga)
        $recusa('TROCA_APARELHO_DIAS', '3');                // piso de 7
    }

    public function test_intervalo_do_rastreamento_aceita_zero_para_desligar(): void
    {
        $this->putJson('/api/parametros/lote', ['alteracoes' => [['chave' => 'RASTREAMENTO_INTERVALO_SEGUNDOS', 'valor' => '0']]])->assertOk();
        $this->putJson('/api/parametros/lote', ['alteracoes' => [['chave' => 'RASTREAMENTO_INTERVALO_SEGUNDOS', 'valor' => '60']]])->assertOk();

        $this->assertDatabaseHas('parametros', ['chave' => 'RASTREAMENTO_INTERVALO_SEGUNDOS', 'valor' => '60']);
    }

    public function test_chave_fora_do_catalogo_e_recusada_no_lote(): void
    {
        $this->putJson('/api/parametros/lote', ['alteracoes' => [['chave' => 'QUALQUER_COISA', 'valor' => '1']]])
            ->assertStatus(422)->assertJsonValidationErrors(['alteracoes.0.chave']);
    }

    public function test_sem_limite_grava_ativo_false_e_numero_reativa(): void
    {
        $this->putJson('/api/parametros/lote', ['alteracoes' => [['chave' => 'CHECKIN_RAIO_METROS', 'ativo' => false]]])->assertOk();
        $this->assertDatabaseHas('parametros', ['empresa_id' => $this->empresa->id, 'chave' => 'CHECKIN_RAIO_METROS', 'ativo' => false]);

        $this->putJson('/api/parametros/lote', ['alteracoes' => [['chave' => 'CHECKIN_RAIO_METROS', 'valor' => '120']]])->assertOk();
        $this->assertDatabaseHas('parametros', ['empresa_id' => $this->empresa->id, 'chave' => 'CHECKIN_RAIO_METROS', 'valor' => '120', 'ativo' => true]);
    }

    public function test_ativo_so_vale_pro_sem_limite(): void
    {
        $this->putJson('/api/parametros/lote', ['alteracoes' => [['chave' => 'CONTRATO_AVISO_DIAS', 'ativo' => false]]])
            ->assertStatus(422)->assertJsonValidationErrors(['alteracoes.0.ativo']);
    }

    public function test_lote_nao_toca_na_empresa_vizinha(): void
    {
        $outra = Empresa::factory()->create();
        Parametro::withoutGlobalScopes()->create(['empresa_id' => $outra->id, 'chave' => 'CONTRATO_AVISO_DIAS', 'valor' => '10', 'ativo' => true]);

        $this->putJson('/api/parametros/lote', ['alteracoes' => [['chave' => 'CONTRATO_AVISO_DIAS', 'valor' => '60']]])->assertOk();

        $this->assertDatabaseHas('parametros', ['empresa_id' => $outra->id, 'chave' => 'CONTRATO_AVISO_DIAS', 'valor' => '10']);
        $this->assertDatabaseHas('parametros', ['empresa_id' => $this->empresa->id, 'chave' => 'CONTRATO_AVISO_DIAS', 'valor' => '60']);
    }

    public function test_promotor_nao_acessa_catalogo_nem_lote(): void
    {
        Sanctum::actingAs(Usuario::factory()->promotor()->create(['empresa_id' => $this->empresa->id]));

        $this->getJson('/api/parametros/catalogo')->assertForbidden();
        $this->putJson('/api/parametros/lote', ['alteracoes' => [['chave' => 'CONTRATO_AVISO_DIAS', 'valor' => '60']]])->assertForbidden();
    }
}

<?php

namespace Tests\Feature;

use App\Models\Empresa;
use App\Models\Parametro;
use App\Models\Usuario;
use App\Support\Rastreamento;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * docs/47-RASTREAMENTO-EXIGENCIA.md — o app informa a situação do rastreamento e o Mapa ao vivo
 * lista os promotores irregulares (quando a empresa pediu).
 */
class RastreamentoExigenciaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Dentro da jornada padrão (07:00–17:00).
        Carbon::setTestNow('2026-09-29 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function parametro(Empresa $empresa, string $chave, string $valor): void
    {
        Parametro::create(['empresa_id' => $empresa->id, 'chave' => $chave, 'valor' => $valor, 'ativo' => true]);
    }

    private function empresaComPainel(): Empresa
    {
        $empresa = Empresa::factory()->create();
        $this->parametro($empresa, 'RASTREAMENTO_INTERVALO_SEGUNDOS', '60');
        $this->parametro($empresa, 'RASTREAMENTO_PAINEL_CONFORMIDADE', 'true');

        return $empresa;
    }

    public function test_promotor_informa_a_situacao_e_invalida_e_recusada(): void
    {
        $empresa = $this->empresaComPainel();
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($promotor);

        $this->patchJson('/api/localizacao/situacao', ['situacao' => 'SO_DURANTE_USO', 'detalhe' => 'Android 14'])->assertNoContent();
        $this->patchJson('/api/localizacao/situacao', ['situacao' => 'INVENTADA'])->assertUnprocessable();

        $promotor->refresh();
        $this->assertSame('SO_DURANTE_USO', $promotor->rastreamento_situacao);
        $this->assertSame('Android 14', $promotor->rastreamento_situacao_detalhe);
        $this->assertNotNull($promotor->rastreamento_situacao_em);
    }

    public function test_so_promotor_informa_situacao(): void
    {
        $empresa = $this->empresaComPainel();
        Sanctum::actingAs(Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]));

        $this->patchJson('/api/localizacao/situacao', ['situacao' => 'ATIVO'])->assertForbidden();
    }

    public function test_conformidade_lista_irregulares_com_o_motivo(): void
    {
        $empresa = $this->empresaComPainel();
        $semPermissao = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id, 'nome' => 'A Sem Permissão']);
        $semSinal = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id, 'nome' => 'B Sem Sinal']);
        $ok = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id, 'nome' => 'C Ok']);
        Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id, 'nome' => 'D Nunca']);

        $semPermissao->forceFill(['rastreamento_situacao' => 'SO_DURANTE_USO', 'rastreamento_situacao_em' => now()->subHour()])->save();
        // Diz que está ativo, mas a última posição é de 20 min atrás (tolerância = máx(5, 3×60s) = 5 min).
        $semSinal->forceFill(['rastreamento_situacao' => 'ATIVO', 'ultima_localizacao_em' => now()->subMinutes(20)])->save();
        $ok->forceFill(['rastreamento_situacao' => 'ATIVO', 'ultima_localizacao_em' => now()->subMinute()])->save();

        Sanctum::actingAs(Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]));
        $resposta = $this->getJson('/api/localizacoes/conformidade')->assertOk();

        $resposta->assertJsonPath('habilitado', true)
            ->assertJsonPath('dentro_da_jornada', true)
            ->assertJsonPath('tolerancia_sem_sinal_minutos', 5);
        $motivos = collect($resposta->json('irregulares'))->pluck('motivo', 'nome')->all();
        $this->assertSame(['A Sem Permissão' => 'SO_DURANTE_USO', 'B Sem Sinal' => 'SEM_SINAL', 'D Nunca' => 'NUNCA_INFORMOU'], $motivos);
    }

    public function test_sem_parametro_do_painel_ou_fora_da_jornada_nao_lista_ninguem(): void
    {
        $empresa = Empresa::factory()->create();
        $this->parametro($empresa, 'RASTREAMENTO_INTERVALO_SEGUNDOS', '60');
        Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs(Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]));

        $this->getJson('/api/localizacoes/conformidade')->assertOk()
            ->assertJsonPath('habilitado', false)
            ->assertJsonCount(0, 'irregulares');

        $this->parametro($empresa, 'RASTREAMENTO_PAINEL_CONFORMIDADE', 'true');
        Carbon::setTestNow('2026-09-29 22:00:00');
        $this->getJson('/api/localizacoes/conformidade')->assertOk()
            ->assertJsonPath('habilitado', true)
            ->assertJsonPath('dentro_da_jornada', false)
            ->assertJsonCount(0, 'irregulares');
    }

    public function test_leitura_dos_parametros_de_exigencia_e_defaults(): void
    {
        $empresa = Empresa::factory()->create();
        $this->assertSame('OPCIONAL', Rastreamento::exigencia($empresa));
        $this->assertTrue(Rastreamento::soNaJornada($empresa));
        $this->assertFalse(Rastreamento::painelConformidade($empresa));

        $this->parametro($empresa, 'RASTREAMENTO_EXIGENCIA', 'obrigatorio');
        $this->parametro($empresa, 'RASTREAMENTO_SO_NA_JORNADA', 'false');
        $this->assertSame('OBRIGATORIO', Rastreamento::exigencia($empresa));
        $this->assertTrue(Rastreamento::dentroDaJornada($empresa, Carbon::parse('2026-09-29 23:30')));
    }
}

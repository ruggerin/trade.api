<?php

namespace Tests\Feature;

use App\Models\Empresa;
use App\Models\Parametro;
use App\Models\ParametroHistorico;
use App\Models\Usuario;
use App\Support\HistoricoParametros;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Auditoria das configurações da empresa: toda mudança de parâmetro fica registrada. */
class ParametroHistoricoTest extends TestCase
{
    use RefreshDatabase;

    private Empresa $empresa;

    private Usuario $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->empresa = Empresa::factory()->create();
        $this->admin = Usuario::factory()->admin()->create(['empresa_id' => $this->empresa->id, 'nome' => 'Ana Admin']);
        Sanctum::actingAs($this->admin);
    }

    public function test_tela_de_configuracoes_registra_antes_depois_quem_e_origem(): void
    {
        Parametro::withoutGlobalScopes()->create(['empresa_id' => $this->empresa->id, 'chave' => 'CONTRATO_AVISO_DIAS', 'valor' => '30', 'ativo' => true]);
        ParametroHistorico::withoutGlobalScopes()->delete();

        $resposta = $this->putJson('/api/parametros/lote', ['alteracoes' => [
            ['chave' => 'CONTRATO_AVISO_DIAS', 'valor' => '45'],
            ['chave' => 'ATRASO_TOLERANCIA_MINUTOS', 'valor' => '15'],
        ]])->assertOk();

        $this->assertDatabaseHas('parametro_historico', [
            'empresa_id' => $this->empresa->id, 'chave' => 'CONTRATO_AVISO_DIAS', 'valor_anterior' => '30', 'valor_novo' => '45',
            'usuario_id' => $this->admin->id, 'usuario_nome' => 'Ana Admin', 'origem' => HistoricoParametros::CONFIGURACOES,
        ]);
        $this->assertDatabaseHas('parametro_historico', ['chave' => 'ATRASO_TOLERANCIA_MINUTOS', 'valor_anterior' => null, 'valor_novo' => '15']);

        $item = collect($resposta->json('areas'))->flatMap(fn ($a) => $a['itens'] ?? [])->firstWhere('chave', 'CONTRATO_AVISO_DIAS');
        $this->assertSame('Ana Admin', $item['ultima_alteracao']['por'] ?? null);

        $this->getJson('/api/parametros/historico?chave=CONTRATO_AVISO_DIAS')
            ->assertOk()
            ->assertJsonCount(1, 'historico')
            ->assertJsonPath('historico.0.valor_anterior', '30')
            ->assertJsonPath('historico.0.valor_novo', '45')
            ->assertJsonPath('historico.0.por', 'Ana Admin');
    }

    public function test_salvar_o_mesmo_valor_nao_gera_registro(): void
    {
        $this->putJson('/api/parametros/lote', ['alteracoes' => [['chave' => 'CONTRATO_AVISO_DIAS', 'valor' => '45']]])->assertOk();
        $antes = ParametroHistorico::withoutGlobalScopes()->count();

        $this->putJson('/api/parametros/lote', ['alteracoes' => [['chave' => 'CONTRATO_AVISO_DIAS', 'valor' => '45']]])->assertOk();

        $this->assertSame($antes, ParametroHistorico::withoutGlobalScopes()->count());
    }

    public function test_avancado_e_desativar_registram_com_origem_avancado(): void
    {
        $parametro = Parametro::withoutGlobalScopes()->create(['empresa_id' => $this->empresa->id, 'chave' => 'CHAVE_LIVRE', 'valor' => 'a', 'ativo' => true]);

        $this->putJson("/api/parametros/{$parametro->uuid}", ['valor' => 'b'])->assertOk();
        $this->deleteJson("/api/parametros/{$parametro->uuid}")->assertNoContent();

        $registros = ParametroHistorico::withoutGlobalScopes()->where('chave', 'CHAVE_LIVRE')->where('origem', HistoricoParametros::AVANCADO)->orderBy('id')->get();
        $this->assertCount(2, $registros);
        $this->assertSame(['a', 'b'], [$registros[0]->valor_anterior, $registros[0]->valor_novo]);
        $this->assertSame([true, false], [$registros[1]->ativo_anterior, $registros[1]->ativo_novo]);
    }

    public function test_mudanca_feita_pelo_suporte_aparece_como_suporte(): void
    {
        Sanctum::actingAs(Usuario::factory()->superadmin()->create());

        $this->postJson("/api/superadmin/empresas/{$this->empresa->uuid}/parametros-padrao")->assertSuccessful();

        $origens = ParametroHistorico::withoutGlobalScopes()->where('empresa_id', $this->empresa->id)->pluck('origem')->unique()->values()->all();
        $this->assertSame([HistoricoParametros::SUPORTE], $origens);
    }

    public function test_historico_de_outra_empresa_nao_aparece_e_gestor_sem_permissao_nao_ve(): void
    {
        $outra = Empresa::factory()->create();
        Parametro::withoutGlobalScopes()->create(['empresa_id' => $outra->id, 'chave' => 'CONTRATO_AVISO_DIAS', 'valor' => '10', 'ativo' => true]);

        $this->getJson('/api/parametros/historico')->assertOk()->assertJsonCount(0, 'historico');

        Sanctum::actingAs(Usuario::factory()->gestor()->create(['empresa_id' => $this->empresa->id]));
        $this->getJson('/api/parametros/historico')->assertForbidden();
    }
}

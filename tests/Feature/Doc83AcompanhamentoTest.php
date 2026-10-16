<?php

namespace Tests\Feature;

use App\Models\AcompanhamentoRegistro;
use App\Models\CampoTipoRegistro;
use App\Models\Empresa;
use App\Models\PontoVenda;
use App\Models\ProdutoAuditoria;
use App\Models\TipoRegistro;
use App\Models\Usuario;
use App\Models\Visita;
use App\Models\VisitaRegistro;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * docs/83 — "ainda está lá?": abertura com gatilho e substituição, respostas (continua, acabou,
 * não consegui ver), alerta resolvido, conflito offline, expiração, cancelamento, gestor e carga.
 */
class Doc83AcompanhamentoTest extends TestCase
{
    use RefreshDatabase;

    private Empresa $empresa;

    private PontoVenda $pdv;

    private Usuario $promotor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->empresa = Empresa::factory()->create();
        $this->pdv = PontoVenda::factory()->create(['empresa_id' => $this->empresa->id]);
        $this->promotor = Usuario::factory()->promotor()->create(['empresa_id' => $this->empresa->id]);
        Sanctum::actingAs($this->promotor);
    }

    private function formulario(array $config = [], bool $comGatilho = false): TipoRegistro
    {
        $tipo = TipoRegistro::create([
            'empresa_id' => $this->empresa->id, 'descricao' => 'Ponto extra',
            'acompanha' => true, 'acompanha_rotulo_continua' => 'Ainda está lá', 'acompanha_rotulo_acabou' => 'Não está mais',
            ...$config,
        ]);
        CampoTipoRegistro::create([
            'tipo_registro_id' => $tipo->id, 'chave' => 'contrato', 'rotulo' => 'Tem contrato?', 'tipo_campo' => 'BOOLEANO', 'ordem' => 0,
        ]);
        if ($comGatilho) {
            $tipo->update(['acompanha_gatilho_chave' => 'contrato', 'acompanha_gatilho_valor' => '0']);
        }

        return $tipo;
    }

    private function visita(?Usuario $usuario = null): Visita
    {
        return Visita::factory()->create([
            'empresa_id' => $this->empresa->id, 'ponto_venda_id' => $this->pdv->id, 'usuario_id' => ($usuario ?? $this->promotor)->id,
        ]);
    }

    private function registrar(Visita $visita, TipoRegistro $tipo, array $valores = ['contrato' => '0'], array $extra = [])
    {
        return $this->postJson("/api/visitas/{$visita->uuid}/registros", [
            'tipo_registro_uuid' => $tipo->uuid,
            'idempotency_key' => (string) Str::uuid(),
            'valores_campos' => $valores,
            ...$extra,
        ], ['X-Canal' => 'mobile']);
    }

    private function responder(Visita $visita, AcompanhamentoRegistro $a, string $resposta, array $extra = [])
    {
        return $this->postJson("/api/visitas/{$visita->uuid}/acompanhamentos/{$a->uuid}/respostas", [
            'resposta' => $resposta,
            'idempotency_key' => (string) Str::uuid(),
            ...$extra,
        ]);
    }

    // ---- abertura ----

    public function test_abre_so_quando_o_gatilho_e_satisfeito(): void
    {
        $tipo = $this->formulario(comGatilho: true);
        $visita = $this->visita();

        $this->registrar($visita, $tipo, ['contrato' => '1'])->assertCreated();
        $this->assertSame(0, AcompanhamentoRegistro::count());

        $this->registrar($visita, $tipo, ['contrato' => '0'])->assertCreated();
        $this->assertSame(1, AcompanhamentoRegistro::where('situacao', 'ABERTO')->count());
    }

    public function test_registro_novo_do_mesmo_item_substitui_e_de_outro_item_nao(): void
    {
        $tipo = $this->formulario();
        $visita = $this->visita();
        $produto = ProdutoAuditoria::factory()->create(['empresa_id' => $this->empresa->id]);

        $this->registrar($visita, $tipo)->assertCreated();
        $this->registrar($visita, $tipo)->assertCreated();
        $this->registrar($visita, $tipo, extra: ['produto_auditoria_uuid' => $produto->uuid])->assertCreated();

        $this->assertSame(1, AcompanhamentoRegistro::where('situacao', 'SUBSTITUIDO')->count());
        $this->assertSame(2, AcompanhamentoRegistro::where('situacao', 'ABERTO')->count());
    }

    public function test_registro_de_resposta_nao_abre_cadeia_nova(): void
    {
        $tipo = $this->formulario();
        $this->registrar($this->visita(), $tipo)->assertCreated();
        $a = AcompanhamentoRegistro::firstOrFail();

        $this->registrar($this->visita(), $tipo, extra: ['acompanhamento_uuid' => $a->uuid])->assertCreated();

        $this->assertSame(1, AcompanhamentoRegistro::count());
        $this->assertSame('ABERTO', $a->fresh()->situacao);
    }

    // ---- respostas ----

    public function test_continua_reagenda_pelo_intervalo_e_nao_consegui_ver_nao_mexe(): void
    {
        $tipo = $this->formulario(['acompanha_intervalo_dias' => 7]);
        $this->registrar($this->visita(), $tipo)->assertCreated();
        $a = AcompanhamentoRegistro::firstOrFail();
        // Como se o registro fosse de 10 dias atrás: a verificação já venceu.
        $a->update(['proxima_verificacao_em' => now()->subDays(3)->toDateString()]);
        $proxima = $a->fresh()->proxima_verificacao_em->toDateString();
        $visita2 = $this->visita();

        $this->responder($visita2, $a, 'NAO_CONSEGUI_VER')->assertCreated();
        $this->assertSame($proxima, $a->fresh()->proxima_verificacao_em->toDateString());

        $this->responder($visita2, $a, 'CONTINUA')->assertCreated();
        $this->assertSame('ABERTO', $a->fresh()->situacao);
        $this->assertNotNull($a->fresh()->ultima_resposta_em);
        // Hoje + 7 dias de calendário.
        $this->assertSame(now()->addDays(7)->toDateString(), $a->fresh()->proxima_verificacao_em->toDateString());
    }

    public function test_acabou_encerra_e_resolve_o_alerta_da_ruptura(): void
    {
        $tipo = $this->formulario(['eh_alerta' => true]);
        $this->registrar($this->visita(), $tipo)->assertCreated();
        $a = AcompanhamentoRegistro::firstOrFail();

        $this->responder($this->visita(), $a, 'ACABOU')->assertCreated()->assertJsonPath('acompanhamento.situacao', 'ENCERRADO');

        $origem = VisitaRegistro::findOrFail($a->registro_origem_id);
        $this->assertNotNull($origem->alerta_resolvido_em);
        $this->assertSame($this->promotor->id, $origem->alerta_resolvido_por_id);
        $this->assertSame('Confirmado na loja', $origem->motivoResolucao->descricao);
    }

    public function test_resposta_idempotente_e_conflito_offline_fica_so_no_historico(): void
    {
        $tipo = $this->formulario();
        $this->registrar($this->visita(), $tipo)->assertCreated();
        $a = AcompanhamentoRegistro::firstOrFail();
        $visita = $this->visita();
        $chave = (string) Str::uuid();

        $this->responder($visita, $a, 'ACABOU', ['idempotency_key' => $chave])->assertCreated();
        $this->responder($visita, $a, 'ACABOU', ['idempotency_key' => $chave])->assertOk();

        // Outro promotor, offline, respondeu "continua" depois: entra no histórico, não reabre.
        $outro = Usuario::factory()->promotor()->create(['empresa_id' => $this->empresa->id]);
        Sanctum::actingAs($outro);
        $this->responder($this->visita($outro), $a, 'CONTINUA')->assertCreated()->assertJsonPath('resposta.aplicada', false);

        $this->assertSame('ENCERRADO', $a->fresh()->situacao);
        $this->assertSame(2, $a->respostas()->count());
    }

    public function test_promotor_nao_responde_em_visita_de_outro_nem_de_outra_loja(): void
    {
        $tipo = $this->formulario();
        $this->registrar($this->visita(), $tipo)->assertCreated();
        $a = AcompanhamentoRegistro::firstOrFail();

        $outro = Usuario::factory()->promotor()->create(['empresa_id' => $this->empresa->id]);
        $this->responder($this->visita($outro), $a, 'CONTINUA')->assertForbidden();

        $outraLoja = Visita::factory()->create(['empresa_id' => $this->empresa->id, 'usuario_id' => $this->promotor->id,
            'ponto_venda_id' => PontoVenda::factory()->create(['empresa_id' => $this->empresa->id])->id]);
        $this->responder($outraLoja, $a, 'CONTINUA')->assertNotFound();
    }

    // ---- fim de vida ----

    public function test_cancelar_o_registro_original_cancela_o_acompanhamento(): void
    {
        \App\Models\Parametro::create(['empresa_id' => $this->empresa->id, 'chave' => 'REGISTRO_CANCELAMENTO_PERMITIDO', 'valor' => 'true', 'ativo' => true]);
        $tipo = $this->formulario();
        $visita = $this->visita();
        $registro = $this->registrar($visita, $tipo)->json('registro.id');

        $this->postJson("/api/visitas/{$visita->uuid}/registros/{$registro}/cancelar")->assertOk();

        $this->assertSame('CANCELADO', AcompanhamentoRegistro::firstOrFail()->situacao);
    }

    public function test_prazo_vencido_expira_e_some_da_carga(): void
    {
        $tipo = $this->formulario(['acompanha_prazo_dias' => 5]);
        $this->registrar($this->visita(), $tipo)->assertCreated();
        $a = AcompanhamentoRegistro::firstOrFail();

        $this->getJson("/api/pontos-venda/{$this->pdv->uuid}")->assertOk()->assertJsonCount(1, 'acompanhamentos_abertos');

        $a->update(['expira_em' => now()->subDays(2)->toDateString()]);
        $this->getJson("/api/pontos-venda/{$this->pdv->uuid}")->assertJsonCount(0, 'acompanhamentos_abertos');
        Artisan::call('acompanhamentos:expirar');
        $this->assertSame('EXPIRADO', $a->fresh()->situacao);
    }

    public function test_carga_do_app_traz_rotulos_e_resumo_e_esconde_no_modo_cego(): void
    {
        $tipo = $this->formulario(['acompanha_ao_continuar' => 'FORMULARIO_PREENCHIDO']);
        $this->registrar($this->visita(), $tipo, ['contrato' => '0'])->assertCreated();

        $item = $this->getJson("/api/pontos-venda/{$this->pdv->uuid}")->json('acompanhamentos_abertos.0');
        $this->assertSame('Ainda está lá', $item['rotulo_continua']);
        $this->assertSame([['rotulo' => 'Tem contrato?', 'valor' => 'Não']], $item['origem']['resumo']);
        $this->assertSame(['contrato' => '0'], $item['origem']['valores_campos']);

        $tipo->update(['acompanha_ao_continuar' => 'FORMULARIO_CEGO']);
        $item = $this->getJson("/api/pontos-venda/{$this->pdv->uuid}")->json('acompanhamentos_abertos.0');
        $this->assertSame([], $item['origem']['resumo']);
        $this->assertNull($item['origem']['valores_campos']);

        // Desligar o acompanhamento congela: some da carga, continua aberto no admin.
        $tipo->update(['acompanha' => false]);
        $this->getJson("/api/pontos-venda/{$this->pdv->uuid}")->assertJsonCount(0, 'acompanhamentos_abertos');
        $this->assertSame('ABERTO', AcompanhamentoRegistro::firstOrFail()->situacao);
    }

    public function test_admin_lista_ve_linha_do_tempo_e_gestor_encerra(): void
    {
        $tipo = $this->formulario();
        $this->registrar($this->visita(), $tipo)->assertCreated();
        $a = AcompanhamentoRegistro::firstOrFail();
        $this->responder($this->visita(), $a, 'CONTINUA')->assertCreated();

        Sanctum::actingAs(Usuario::factory()->admin()->create(['empresa_id' => $this->empresa->id]));
        $this->getJson('/api/acompanhamentos?situacao=ABERTO')->assertOk()->assertJsonCount(1, 'acompanhamentos');
        $this->getJson("/api/acompanhamentos/{$a->uuid}")->assertOk()
            ->assertJsonPath('acompanhamento.respostas.0.resposta', 'CONTINUA')
            ->assertJsonPath('acompanhamento.origem.formulario', 'Ponto extra');

        $this->postJson("/api/acompanhamentos/{$a->uuid}/encerrar")->assertOk()
            ->assertJsonPath('acompanhamento.situacao', 'ENCERRADO_PELO_GESTOR');
        $this->postJson("/api/acompanhamentos/{$a->uuid}/encerrar")->assertStatus(422);
    }

    // ---- configuração ----

    public function test_configuracao_valida_rotulos_gatilho_e_justificativa(): void
    {
        Sanctum::actingAs(Usuario::factory()->admin()->create(['empresa_id' => $this->empresa->id]));
        $justificativa = TipoRegistro::create(['empresa_id' => $this->empresa->id, 'descricao' => 'Por que saiu?']);
        $base = [
            'descricao' => 'Ruptura',
            'campos' => [['chave' => 'falta', 'rotulo' => 'Falta?', 'tipo_campo' => 'BOOLEANO']],
            'acompanha' => true,
        ];

        $this->postJson('/api/tipos-registro', $base)->assertStatus(422)->assertJsonValidationErrors(['acompanha_rotulo_continua', 'acompanha_rotulo_acabou']);
        $this->postJson('/api/tipos-registro', $base + ['acompanha_rotulo_continua' => 'Ainda falta', 'acompanha_rotulo_acabou' => 'Já tem', 'acompanha_gatilho_chave' => 'outra'])
            ->assertStatus(422)->assertJsonValidationErrors('acompanha_gatilho_chave');

        $id = $this->postJson('/api/tipos-registro', $base + [
            'acompanha_rotulo_continua' => 'Ainda falta', 'acompanha_rotulo_acabou' => 'Já tem',
            'acompanha_gatilho_chave' => 'falta', 'acompanha_gatilho_valor' => '1', 'acompanha_intervalo_dias' => 0,
            'acompanha_ao_continuar' => 'FORMULARIO_CEGO', 'acompanha_formulario_acabou_uuid' => $justificativa->uuid, 'acompanha_prazo_dias' => 30,
        ])->assertCreated()
            ->assertJsonPath('tipo_registro.acompanha_formulario_acabou.descricao', 'Por que saiu?')
            ->assertJsonPath('tipo_registro.acompanha_ao_continuar', 'FORMULARIO_CEGO')
            ->json('tipo_registro.id');

        $this->putJson("/api/tipos-registro/{$id}", ['acompanha_formulario_acabou_uuid' => $id])->assertStatus(422);
    }
    // ---- relatórios ----

    public function test_gerador_de_relatorios_tem_a_entidade_com_duracao(): void
    {
        $tipo = $this->formulario();
        $this->registrar($this->visita(), $tipo)->assertCreated();
        $a = AcompanhamentoRegistro::firstOrFail();
        $this->responder($this->visita(), $a, 'ACABOU')->assertCreated();

        Sanctum::actingAs(Usuario::factory()->admin()->create(['empresa_id' => $this->empresa->id]));
        $entidades = collect($this->getJson('/api/relatorios-personalizados/catalogo')->assertOk()->json('data'))->pluck('chave');
        $this->assertContains('acompanhamento', $entidades);

        $metricas = collect($this->getJson('/api/relatorios-personalizados/catalogo?entidade=acompanhamento')->assertOk()->json('data.metricas'))->pluck('chave');
        $this->assertContains('duracao_media', $metricas);
    }
}

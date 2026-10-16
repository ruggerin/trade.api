<?php

namespace Tests\Feature;

use App\Models\AcompanhamentoRegistro;
use App\Models\Empresa;
use App\Models\PontoVenda;
use App\Models\Usuario;
use App\Models\Visita;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * docs/83 de ponta a ponta, só pela API: o admin cadastra e edita o formulário com acompanhamento,
 * o promotor coleta, volta dias depois, confirma com o formulário preenchido, volta de novo e diz
 * que acabou (com justificativa) — e o admin vê a linha do tempo. A visita em si sai da factory
 * (o check-in tem testes próprios); todo o resto passa pelas rotas.
 */
class Doc83FluxoCompletoTest extends TestCase
{
    use RefreshDatabase;

    private Empresa $empresa;

    private PontoVenda $pdv;

    private Usuario $admin;

    private Usuario $promotor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->empresa = Empresa::factory()->create();
        $this->pdv = PontoVenda::factory()->create(['empresa_id' => $this->empresa->id]);
        $this->admin = Usuario::factory()->admin()->create(['empresa_id' => $this->empresa->id]);
        $this->promotor = Usuario::factory()->promotor()->create(['empresa_id' => $this->empresa->id]);
    }

    private function comoAdmin(): void
    {
        Sanctum::actingAs($this->admin);
    }

    private function comoPromotor(): void
    {
        Sanctum::actingAs($this->promotor);
    }

    private function novaVisita(): Visita
    {
        return Visita::factory()->create([
            'empresa_id' => $this->empresa->id, 'ponto_venda_id' => $this->pdv->id, 'usuario_id' => $this->promotor->id,
        ]);
    }

    private function registrar(Visita $visita, string $tipoUuid, array $valores, array $extra = [])
    {
        return $this->postJson("/api/visitas/{$visita->uuid}/registros", [
            'tipo_registro_uuid' => $tipoUuid,
            'idempotency_key' => (string) Str::uuid(),
            'valores_campos' => $valores,
            ...$extra,
        ], ['X-Canal' => 'mobile']);
    }

    private function responder(Visita $visita, string $acompanhamentoUuid, array $dados)
    {
        return $this->postJson("/api/visitas/{$visita->uuid}/acompanhamentos/{$acompanhamentoUuid}/respostas", $dados, ['X-Canal' => 'mobile']);
    }

    /** Cadastro (passo 6 do editor) e edição, com as validações que o admin mostra. */
    private function cadastrarFormularios(): array
    {
        $this->comoAdmin();

        $justificativa = $this->postJson('/api/tipos-registro', [
            'descricao' => 'Por que saiu?',
            'campos' => [['chave' => 'motivo', 'rotulo' => 'Motivo', 'tipo_campo' => 'TEXTO']],
        ])->assertCreated()->json('tipo_registro.id');

        $campos = [
            ['chave' => 'contrato', 'rotulo' => 'Tem contrato?', 'tipo_campo' => 'BOOLEANO'],
            ['chave' => 'frentes', 'rotulo' => 'Frentes', 'tipo_campo' => 'NUMERO'],
        ];

        // Ligado sem os textos dos botões: o admin barra.
        $this->postJson('/api/tipos-registro', [
            'descricao' => 'Ponto extra', 'campos' => $campos, 'acompanha' => true,
        ])->assertStatus(422)->assertJsonValidationErrors(['acompanha_rotulo_continua', 'acompanha_rotulo_acabou']);

        // Gatilho apontando pra uma pergunta que não existe no formulário.
        $this->postJson('/api/tipos-registro', [
            'descricao' => 'Ponto extra', 'campos' => $campos, 'acompanha' => true,
            'acompanha_rotulo_continua' => 'Ainda está lá', 'acompanha_rotulo_acabou' => 'Não está mais',
            'acompanha_gatilho_chave' => 'nao_existe', 'acompanha_gatilho_valor' => '0',
        ])->assertStatus(422)->assertJsonValidationErrors('acompanha_gatilho_chave');

        $criado = $this->postJson('/api/tipos-registro', [
            'descricao' => 'Ponto extra',
            'campos' => $campos,
            'acompanha' => true,
            'acompanha_gatilho_chave' => 'contrato',
            'acompanha_gatilho_valor' => '0',
            'acompanha_intervalo_dias' => 7,
            'acompanha_rotulo_continua' => 'Ainda está lá',
            'acompanha_rotulo_acabou' => 'Não está mais',
            'acompanha_ao_continuar' => 'FORMULARIO_PREENCHIDO',
            'acompanha_formulario_acabou_uuid' => $justificativa,
            'acompanha_prazo_dias' => 60,
        ])->assertCreated()
            ->assertJsonPath('tipo_registro.acompanha', true)
            ->assertJsonPath('tipo_registro.acompanha_gatilho_chave', 'contrato')
            ->assertJsonPath('tipo_registro.acompanha_intervalo_dias', 7)
            ->assertJsonPath('tipo_registro.acompanha_ao_continuar', 'FORMULARIO_PREENCHIDO')
            ->assertJsonPath('tipo_registro.acompanha_formulario_acabou.id', $justificativa);
        $tipo = $criado->json('tipo_registro.id');

        // Edição: muda intervalo e texto; a justificativa não pode ser o próprio formulário.
        $this->putJson("/api/tipos-registro/{$tipo}", ['acompanha_formulario_acabou_uuid' => $tipo])
            ->assertStatus(422)->assertJsonValidationErrors('acompanha_formulario_acabou_uuid');
        $this->putJson("/api/tipos-registro/{$tipo}", [
            'acompanha_intervalo_dias' => 3,
            'acompanha_rotulo_continua' => 'Continua montado',
        ])->assertOk()
            ->assertJsonPath('tipo_registro.acompanha_intervalo_dias', 3)
            ->assertJsonPath('tipo_registro.acompanha_rotulo_continua', 'Continua montado')
            // O que não veio no PUT continua como estava.
            ->assertJsonPath('tipo_registro.acompanha_gatilho_chave', 'contrato')
            ->assertJsonPath('tipo_registro.acompanha_formulario_acabou.id', $justificativa);

        return [$tipo, $justificativa];
    }

    public function test_fluxo_completo_cadastro_coleta_continua_acabou_e_linha_do_tempo(): void
    {
        $this->travelTo(now()->setTime(10, 0));
        [$tipo, $justificativa] = $this->cadastrarFormularios();

        // ---- Visita 1: coleta. Com contrato não acompanha; sem contrato abre o acompanhamento.
        $this->comoPromotor();
        $visita1 = $this->novaVisita();
        $this->registrar($visita1, $tipo, ['contrato' => '1', 'frentes' => '2'])->assertCreated();
        $this->assertSame(0, AcompanhamentoRegistro::count());

        $this->registrar($visita1, $tipo, ['contrato' => '0', 'frentes' => '4'])->assertCreated();
        $acomp = AcompanhamentoRegistro::sole();
        $this->assertSame('ABERTO', $acomp->situacao);
        $this->assertSame(now()->addDays(3)->toDateString(), $acomp->proxima_verificacao_em->toDateString());
        $this->assertSame(now()->addDays(60)->toDateString(), $acomp->expira_em->toDateString());

        // ---- Visita 2, 4 dias depois: a carga do app traz o cartão já com a última coleta.
        $this->travel(4)->days();
        $cartao = $this->getJson("/api/pontos-venda/{$this->pdv->uuid}")->assertOk()
            ->assertJsonCount(1, 'acompanhamentos_abertos')
            ->json('acompanhamentos_abertos.0');
        $this->assertSame($acomp->uuid, $cartao['id']);
        $this->assertSame('Continua montado', $cartao['rotulo_continua']);
        $this->assertSame('Não está mais', $cartao['rotulo_acabou']);
        $this->assertSame('FORMULARIO_PREENCHIDO', $cartao['ao_continuar']);
        $this->assertSame($justificativa, $cartao['formulario_acabou']['id']);
        $this->assertSame('4', (string) $cartao['origem']['valores_campos']['frentes']);
        $this->assertNotEmpty($cartao['origem']['resumo']);

        // "Continua" com o formulário: o registro sobe primeiro, ligado ao acompanhamento...
        $visita2 = $this->novaVisita();
        $registro2 = $this->registrar($visita2, $tipo, ['contrato' => '0', 'frentes' => '6'], ['acompanhamento_uuid' => $acomp->uuid])
            ->assertCreated()->json('registro.id');
        // ...e não abre outra cadeia (nem substitui a atual).
        $this->assertSame(1, AcompanhamentoRegistro::count());

        $chave = (string) Str::uuid();
        $this->responder($visita2, $acomp->uuid, ['resposta' => 'CONTINUA', 'idempotency_key' => $chave, 'registro_uuid' => $registro2])
            ->assertCreated()->assertJsonPath('acompanhamento.situacao', 'ABERTO');
        // Reenvio da fila (mesma chave): não duplica.
        $this->responder($visita2, $acomp->uuid, ['resposta' => 'CONTINUA', 'idempotency_key' => $chave, 'registro_uuid' => $registro2])
            ->assertOk();
        $this->assertSame(1, $acomp->respostas()->count());
        $this->assertSame(now()->addDays(3)->toDateString(), $acomp->fresh()->proxima_verificacao_em->toDateString());

        // A próxima carga já abre com a coleta nova.
        $this->assertSame('6', (string) $this->getJson("/api/pontos-venda/{$this->pdv->uuid}")
            ->json('acompanhamentos_abertos.0.origem.valores_campos.frentes'));

        // ---- Visita 3: acabou, com justificativa.
        $this->travel(5)->days();
        $visita3 = $this->novaVisita();
        $registro3 = $this->registrar($visita3, $justificativa, ['motivo' => 'Loja desmontou'], ['acompanhamento_uuid' => $acomp->uuid])
            ->assertCreated()->json('registro.id');
        $this->responder($visita3, $acomp->uuid, ['resposta' => 'ACABOU', 'idempotency_key' => (string) Str::uuid(), 'registro_uuid' => $registro3])
            ->assertCreated()->assertJsonPath('acompanhamento.situacao', 'ENCERRADO');

        // Some da carga do app.
        $this->getJson("/api/pontos-venda/{$this->pdv->uuid}")->assertJsonCount(0, 'acompanhamentos_abertos');

        // Resposta atrasada de outro celular depois de encerrado: fica no histórico, não reabre.
        $this->responder($this->novaVisita(), $acomp->uuid, ['resposta' => 'CONTINUA', 'idempotency_key' => (string) Str::uuid()])
            ->assertJsonPath('resposta.aplicada', false)
            ->assertJsonPath('acompanhamento.situacao', 'ENCERRADO');

        // ---- Admin: lista e linha do tempo.
        $this->comoAdmin();
        $this->getJson('/api/acompanhamentos?situacao=ABERTO')->assertOk()->assertJsonCount(0, 'acompanhamentos');
        $this->getJson('/api/acompanhamentos?situacao=ENCERRADO')->assertOk()
            ->assertJsonCount(1, 'acompanhamentos')
            ->assertJsonPath('acompanhamentos.0.id', $acomp->uuid)
            ->assertJsonPath('acompanhamentos.0.dias_em_aberto', 9);

        $linha = $this->getJson("/api/acompanhamentos/{$acomp->uuid}")->assertOk()->json('acompanhamento');
        $this->assertSame('ENCERRADO', $linha['situacao']);
        $this->assertSame('Ponto extra', $linha['origem']['formulario']);
        $this->assertSame(['CONTINUA', 'ACABOU', 'CONTINUA'], array_column($linha['respostas'], 'resposta'));
        $this->assertSame([true, true, false], array_column($linha['respostas'], 'aplicada'));
        $this->assertSame($registro2, $linha['respostas'][0]['registro']['id']);
        $this->assertSame('Por que saiu?', $linha['respostas'][1]['registro']['formulario']);

        // Encerrar de novo pelo gestor: já está encerrado.
        $this->postJson("/api/acompanhamentos/{$acomp->uuid}/encerrar")->assertStatus(422);
    }

    public function test_promotor_nao_ve_a_lista_do_admin_nem_encerra(): void
    {
        [$tipo] = $this->cadastrarFormularios();
        $this->comoPromotor();
        $this->registrar($this->novaVisita(), $tipo, ['contrato' => '0', 'frentes' => '1'])->assertCreated();
        $acomp = AcompanhamentoRegistro::sole();

        $this->postJson("/api/acompanhamentos/{$acomp->uuid}/encerrar")->assertForbidden();

        $this->comoAdmin();
        $this->postJson("/api/acompanhamentos/{$acomp->uuid}/encerrar")->assertOk()
            ->assertJsonPath('acompanhamento.situacao', 'ENCERRADO_PELO_GESTOR')
            ->assertJsonPath('acompanhamento.encerrado_por', $this->admin->nome);
    }
}

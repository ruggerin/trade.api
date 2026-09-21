<?php

namespace Tests\Feature;

use App\Models\Empresa;
use App\Models\PontoVenda;
use App\Models\TipoRegistro;
use App\Models\Usuario;
use App\Models\Visita;
use App\Models\VisitaRegistro;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Feedback em registro de visita (docs/28-RELATORIOS-FEEDBACK-HISTORICO.md §3): feed de
 * comentários promotor <-> admin/gestor, badge de não lidos e evento no Painel de Atividades.
 */
class ComentarioRegistroTest extends TestCase
{
    use RefreshDatabase;

    private Empresa $empresa;

    private Usuario $promotor;

    private Usuario $admin;

    private Visita $visita;

    private VisitaRegistro $registro;

    protected function setUp(): void
    {
        parent::setUp();
        $this->empresa = Empresa::factory()->create();
        $this->promotor = Usuario::factory()->promotor()->create(['empresa_id' => $this->empresa->id, 'nome' => 'Ana']);
        $this->admin = Usuario::factory()->admin()->create(['empresa_id' => $this->empresa->id, 'nome' => 'Gestora']);
        $pdv = PontoVenda::factory()->create(['empresa_id' => $this->empresa->id]);
        $this->visita = Visita::factory()->create(['empresa_id' => $this->empresa->id, 'usuario_id' => $this->promotor->id, 'ponto_venda_id' => $pdv->id]);
        $tipo = TipoRegistro::create(['empresa_id' => $this->empresa->id, 'descricao' => 'Ruptura', 'eh_ruptura' => true]);
        $this->registro = VisitaRegistro::create(['visita_id' => $this->visita->id, 'tipo_registro_id' => $tipo->id, 'ruptura' => true]);
    }

    private function url(): string
    {
        return "/api/visitas/{$this->visita->uuid}/registros/{$this->registro->uuid}/comentarios";
    }

    public function test_admin_e_promotor_conversam_no_mesmo_feed(): void
    {
        Sanctum::actingAs($this->admin);
        $this->postJson($this->url(), ['texto' => 'Pedido chega sexta'])->assertCreated()
            ->assertJsonPath('comentario.meu', true)
            ->assertJsonPath('comentario.autor.nome', 'Gestora');

        Sanctum::actingAs($this->promotor);
        $this->postJson($this->url(), ['texto' => 'Obrigada!'])->assertCreated();

        $this->getJson($this->url())->assertOk()
            ->assertJsonCount(2, 'comentarios')
            ->assertJsonPath('comentarios.0.texto', 'Pedido chega sexta')
            ->assertJsonPath('comentarios.0.meu', false)
            ->assertJsonPath('comentarios.1.meu', true);
    }

    public function test_texto_e_obrigatorio(): void
    {
        Sanctum::actingAs($this->admin);
        $this->postJson($this->url(), ['texto' => ''])->assertUnprocessable();
    }

    public function test_promotor_nao_comenta_em_visita_de_outro(): void
    {
        $outro = Usuario::factory()->promotor()->create(['empresa_id' => $this->empresa->id]);
        Sanctum::actingAs($outro);

        $this->postJson($this->url(), ['texto' => 'oi'])->assertForbidden();
        $this->getJson($this->url())->assertForbidden();
    }

    public function test_registro_de_outra_visita_da_404(): void
    {
        $outraVisita = Visita::factory()->create(['empresa_id' => $this->empresa->id, 'usuario_id' => $this->promotor->id, 'ponto_venda_id' => $this->visita->ponto_venda_id]);
        Sanctum::actingAs($this->admin);

        $this->getJson("/api/visitas/{$outraVisita->uuid}/registros/{$this->registro->uuid}/comentarios")->assertNotFound();
    }

    public function test_badge_de_nao_lidos_some_ao_abrir_o_feed(): void
    {
        Sanctum::actingAs($this->admin);
        $this->postJson($this->url(), ['texto' => 'Pedido chega sexta'])->assertCreated();
        // Quem escreveu não vê o próprio comentário como não lido.
        $this->getJson('/api/comentarios/nao-lidos')->assertOk()->assertJsonPath('total', 0);

        Sanctum::actingAs($this->promotor);
        $this->getJson('/api/comentarios/nao-lidos')->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('registros.0.registro_id', $this->registro->uuid)
            ->assertJsonPath('registros.0.ultimo.autor', 'Gestora')
            ->assertJsonPath('registros.0.ultimo.texto', 'Pedido chega sexta');

        $this->getJson($this->url())->assertOk();
        $this->getJson('/api/comentarios/nao-lidos')->assertOk()->assertJsonPath('total', 0);

        // Comentário novo depois da leitura volta a acender o badge.
        $this->travel(5)->seconds();
        Sanctum::actingAs($this->admin);
        $this->postJson($this->url(), ['texto' => 'Confirmado'])->assertCreated();
        Sanctum::actingAs($this->promotor);
        $this->getJson('/api/comentarios/nao-lidos')->assertOk()->assertJsonPath('total', 1);
    }

    public function test_promotor_so_ve_nao_lidos_das_proprias_visitas(): void
    {
        Sanctum::actingAs($this->admin);
        $this->postJson($this->url(), ['texto' => 'Só pra Ana'])->assertCreated();

        Sanctum::actingAs(Usuario::factory()->promotor()->create(['empresa_id' => $this->empresa->id]));
        $this->getJson('/api/comentarios/nao-lidos')->assertOk()->assertJsonPath('total', 0);
    }

    public function test_admin_ve_resposta_do_promotor_no_badge_e_no_painel_de_atividades(): void
    {
        Sanctum::actingAs($this->promotor);
        $this->postJson($this->url(), ['texto' => 'Faltou de novo'])->assertCreated();

        Sanctum::actingAs($this->admin);
        $this->getJson('/api/comentarios/nao-lidos')->assertOk()->assertJsonPath('total', 1);

        $eventos = collect($this->getJson('/api/atividades')->assertOk()->json('eventos'));
        $comentario = $eventos->firstWhere('tipo_evento', 'COMENTARIO');
        $this->assertNotNull($comentario);
        $this->assertSame('Faltou de novo', $comentario['comentario']['texto']);
        $this->assertSame('Ana', $comentario['usuario']['nome']);
    }

    public function test_comentario_de_admin_nao_vira_evento_no_painel(): void
    {
        Sanctum::actingAs($this->admin);
        $this->postJson($this->url(), ['texto' => 'Resposta do gestor'])->assertCreated();

        $eventos = collect($this->getJson('/api/atividades')->assertOk()->json('eventos'));
        $this->assertNull($eventos->firstWhere('tipo_evento', 'COMENTARIO'));
    }

    public function test_registro_traz_contagem_de_comentarios_e_novos_sem_abrir_o_feed(): void
    {
        Sanctum::actingAs($this->promotor);
        $this->postJson($this->url(), ['texto' => 'Faltou'])->assertCreated();
        $this->postJson($this->url(), ['texto' => 'De novo'])->assertCreated();

        // Admin ainda não leu: 2 comentários, os 2 novos.
        Sanctum::actingAs($this->admin);
        $registro = fn () => $this->getJson("/api/visitas/{$this->visita->uuid}")->assertOk()->json('visita.registros.0');
        $this->assertSame(2, $registro()['comentarios_count']);
        $this->assertSame(2, $registro()['comentarios_novos']);

        // Abrir o feed marca como lido: continua 2 no total, 0 novos.
        $this->getJson($this->url())->assertOk();
        $this->assertSame(2, $registro()['comentarios_count']);
        $this->assertSame(0, $registro()['comentarios_novos']);

        // Um comentário do próprio admin não conta como novo pra ele.
        $this->postJson($this->url(), ['texto' => 'Pedido chega sexta'])->assertCreated();
        $this->assertSame(3, $registro()['comentarios_count']);
        $this->assertSame(0, $registro()['comentarios_novos']);
    }

    public function test_painel_de_atividades_traz_contagem_no_evento_de_comentario(): void
    {
        Sanctum::actingAs($this->promotor);
        $this->postJson($this->url(), ['texto' => 'Faltou'])->assertCreated();

        Sanctum::actingAs($this->admin);
        $evento = collect($this->getJson('/api/atividades')->assertOk()->json('eventos'))->firstWhere('tipo_evento', 'COMENTARIO');
        $this->assertSame(1, $evento['comentario']['comentarios_count']);
        $this->assertSame(1, $evento['comentario']['comentarios_novos']);
    }

    public function test_isolamento_por_empresa_no_badge(): void
    {
        Sanctum::actingAs($this->promotor);
        $this->postJson($this->url(), ['texto' => 'Faltou'])->assertCreated();

        $outraEmpresa = Empresa::factory()->create();
        Sanctum::actingAs(Usuario::factory()->admin()->create(['empresa_id' => $outraEmpresa->id]));
        $this->getJson('/api/comentarios/nao-lidos')->assertOk()->assertJsonPath('total', 0);
    }
}

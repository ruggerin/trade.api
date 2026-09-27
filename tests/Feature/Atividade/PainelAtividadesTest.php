<?php

namespace Tests\Feature\Atividade;

use App\Models\Empresa;
use App\Models\Parametro;
use App\Models\PontoVenda;
use App\Models\TipoRegistro;
use App\Models\Usuario;
use App\Models\Visita;
use App\Models\VisitaRegistro;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Painel de Atividades — feed único (check-in/checkout/alertas) pra ADMIN/GESTOR acompanhar as
 * visitas do dia, substituto do grupo de WhatsApp. Ver AtividadeController e
 * docs/17-PAINEL-ATIVIDADES.md.
 */
class PainelAtividadesTest extends TestCase
{
    use RefreshDatabase;

    private function abrirVisita(Usuario $promotor, PontoVenda $pdv): string
    {
        Sanctum::actingAs($promotor);

        return $this->postJson('/api/visitas', [
            'ponto_venda_uuid' => $pdv->uuid,
            'latitude' => $pdv->latitude,
            'longitude' => $pdv->longitude,
        ])->json('visita.id');
    }

    private function criarTipoAlerta(Empresa $empresa, string $descricao = 'Ruptura crítica'): TipoRegistro
    {
        return TipoRegistro::create([
            'empresa_id' => $empresa->id, 'descricao' => $descricao, 'eh_alerta' => true,
        ]);
    }

    private function criarRegistro(string $visitaUuid, string $tipoUuid): string
    {
        return $this->postJson("/api/visitas/{$visitaUuid}/registros", [
            'tipo_registro_uuid' => $tipoUuid, 'observacao' => 'Teste',
        ])->json('registro.id');
    }

    public function test_promotor_nao_acessa_o_painel(): void
    {
        $empresa = Empresa::factory()->create();
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($promotor);

        $this->getJson('/api/atividades')->assertForbidden();
    }

    public function test_gestor_ve_checkin_e_checkout_no_feed(): void
    {
        $empresa = Empresa::factory()->create();
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        $visitaUuid = $this->abrirVisita($promotor, $pdv);
        $this->patchJson("/api/visitas/{$visitaUuid}/checkout", [
            'latitude' => $pdv->latitude, 'longitude' => $pdv->longitude,
        ])->assertOk();

        $gestor = Usuario::factory()->gestor()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($gestor);

        $response = $this->getJson('/api/atividades')->assertOk();
        $eventos = collect($response->json('eventos'));

        $inicio = $eventos->firstWhere('tipo_evento', 'VISITA_INICIADA');
        $fim = $eventos->firstWhere('tipo_evento', 'VISITA_FINALIZADA');

        $this->assertNotNull($inicio);
        $this->assertNotNull($fim);
        $this->assertSame((float) $pdv->latitude, $inicio['localizacao']['latitude']);
        $this->assertSame((float) $pdv->longitude, $inicio['localizacao']['longitude']);
        $this->assertSame([], $fim['imagens']);
    }

    public function test_alerta_aparece_no_feed_com_o_registro_aninhado(): void
    {
        $empresa = Empresa::factory()->create();
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        $tipoAlerta = $this->criarTipoAlerta($empresa);
        $visitaUuid = $this->abrirVisita($promotor, $pdv);
        $registroUuid = $this->criarRegistro($visitaUuid, $tipoAlerta->uuid);

        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/atividades')->assertOk();
        $alerta = collect($response->json('eventos'))->firstWhere('tipo_evento', 'ALERTA');

        $this->assertNotNull($alerta);
        $this->assertSame($registroUuid, $alerta['registro']['id']);
        $this->assertNull($alerta['registro']['alerta_resolvido_em']);
    }

    public function test_registro_de_tipo_sem_eh_alerta_nao_aparece_como_alerta(): void
    {
        $empresa = Empresa::factory()->create();
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        $tipoComum = TipoRegistro::where('empresa_id', $empresa->id)->where('descricao', 'Observação')->first();
        $visitaUuid = $this->abrirVisita($promotor, $pdv);
        $this->criarRegistro($visitaUuid, $tipoComum->uuid);

        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/atividades')->assertOk();
        $this->assertFalse(collect($response->json('eventos'))->contains('tipo_evento', 'ALERTA'));
    }

    public function test_filtro_por_tipo_de_registro_especifico_suprime_checkin_e_checkout(): void
    {
        $empresa = Empresa::factory()->create();
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        $tipoPontoExtra = $this->criarTipoAlerta($empresa, 'Ponto extra');
        $visitaUuid = $this->abrirVisita($promotor, $pdv);
        $this->criarRegistro($visitaUuid, $tipoPontoExtra->uuid);

        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($admin);

        $response = $this->getJson("/api/atividades?tipo_registro_uuid={$tipoPontoExtra->uuid}")->assertOk();
        $eventos = collect($response->json('eventos'));

        $this->assertTrue($eventos->every(fn ($e) => $e['tipo_evento'] === 'ALERTA'));
        $this->assertCount(1, $eventos);
    }

    public function test_filtro_pendentes_so_mostra_alertas_nao_resolvidos(): void
    {
        $empresa = Empresa::factory()->create();
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        $tipoAlerta = $this->criarTipoAlerta($empresa);
        $visitaUuid = $this->abrirVisita($promotor, $pdv);
        $registroResolvidoUuid = $this->criarRegistro($visitaUuid, $tipoAlerta->uuid);
        $this->criarRegistro($visitaUuid, $tipoAlerta->uuid);

        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($admin);
        $this->postJson("/api/visitas/{$visitaUuid}/registros/{$registroResolvidoUuid}/resolver-alerta")->assertOk();

        $response = $this->getJson('/api/atividades?pendentes=1')->assertOk();
        $eventos = collect($response->json('eventos'));

        $this->assertCount(1, $eventos);
        $this->assertNotSame($registroResolvidoUuid, $eventos->first()['registro']['id']);
    }

    public function test_pagina_o_feed_em_paginas_de_20(): void
    {
        $empresa = Empresa::factory()->create();
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        $tipoAlerta = $this->criarTipoAlerta($empresa);
        $visitaUuid = $this->abrirVisita($promotor, $pdv);

        // 25 alertas + 1 check-in (abrirVisita) = 26 eventos no total, sem filtro nenhum.
        for ($i = 0; $i < 25; $i++) {
            $this->criarRegistro($visitaUuid, $tipoAlerta->uuid);
        }

        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($admin);

        $pagina1 = $this->getJson('/api/atividades')->assertOk();
        $this->assertCount(20, $pagina1->json('eventos'));
        $this->assertSame(1, $pagina1->json('meta.current_page'));
        $this->assertSame(2, $pagina1->json('meta.last_page'));
        $this->assertSame(26, $pagina1->json('meta.total'));
        $this->assertSame(20, $pagina1->json('meta.per_page'));

        $pagina2 = $this->getJson('/api/atividades?page=2')->assertOk();
        $this->assertCount(6, $pagina2->json('eventos'));
        $this->assertSame(2, $pagina2->json('meta.current_page'));
        $this->assertSame(26, $pagina2->json('meta.total'));
    }

    public function test_isolamento_entre_empresas(): void
    {
        $empresaA = Empresa::factory()->create();
        $empresaB = Empresa::factory()->create();
        $pdvA = PontoVenda::factory()->create(['empresa_id' => $empresaA->id]);
        $promotorA = Usuario::factory()->promotor()->create(['empresa_id' => $empresaA->id]);
        $this->abrirVisita($promotorA, $pdvA);

        $adminB = Usuario::factory()->admin()->create(['empresa_id' => $empresaB->id]);
        Sanctum::actingAs($adminB);

        $response = $this->getJson('/api/atividades')->assertOk();
        $this->assertCount(0, $response->json('eventos'));
    }

    public function test_eventos_tem_id_estavel_e_chegada_traz_fora_do_raio(): void
    {
        $empresa = Empresa::factory()->create();
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        $visitaUuid = $this->abrirVisita($promotor, $pdv);

        Sanctum::actingAs(Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]));
        $chegada = collect($this->getJson('/api/atividades')->assertOk()->json('eventos'))->firstWhere('tipo_evento', 'VISITA_INICIADA');

        $this->assertSame("chegada:{$visitaUuid}", $chegada['id']);
        $this->assertFalse($chegada['localizacao']['fora_do_raio']);

        // Mesma visita, gravada como se tivesse chegado a 900 m — o raio padrão é 200 m.
        Visita::where('uuid', $visitaUuid)->update(['inicio_distancia_metros' => 900]);
        $chegada = collect($this->getJson('/api/atividades')->json('eventos'))->firstWhere('tipo_evento', 'VISITA_INICIADA');
        $this->assertTrue($chegada['localizacao']['fora_do_raio']);
    }

    public function test_saida_traz_tempo_na_loja_e_total_de_fotos(): void
    {
        $empresa = Empresa::factory()->create();
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        $visitaUuid = $this->abrirVisita($promotor, $pdv);
        $this->travel(47)->minutes();
        $this->patchJson("/api/visitas/{$visitaUuid}/checkout", ['latitude' => $pdv->latitude, 'longitude' => $pdv->longitude])->assertOk();

        Sanctum::actingAs(Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]));
        $saida = collect($this->getJson('/api/atividades')->json('eventos'))->firstWhere('tipo_evento', 'VISITA_FINALIZADA');

        $this->assertSame("saida:{$visitaUuid}", $saida['id']);
        $this->assertSame(47, $saida['resumo']['duracao_minutos']);
        $this->assertSame(0, $saida['resumo']['total_fotos']);
    }

    public function test_respostas_do_mesmo_formulario_na_mesma_visita_viram_um_post_so(): void
    {
        $empresa = Empresa::factory()->create();
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        $visitaUuid = $this->abrirVisita($promotor, $pdv);
        $visita = Visita::where('uuid', $visitaUuid)->first();
        $pesquisa = TipoRegistro::create(['empresa_id' => $empresa->id, 'descricao' => 'Pesquisa de preço']);
        $pontoExtra = TipoRegistro::create(['empresa_id' => $empresa->id, 'descricao' => 'Ponto extra']);
        $item1 = VisitaRegistro::create(['visita_id' => $visita->id, 'tipo_registro_id' => $pesquisa->id, 'valores_campos' => ['preco' => '7.98']]);
        $item2 = VisitaRegistro::create(['visita_id' => $visita->id, 'tipo_registro_id' => $pesquisa->id, 'valores_campos' => ['preco' => '8.48']]);
        VisitaRegistro::create(['visita_id' => $visita->id, 'tipo_registro_id' => $pontoExtra->id, 'valores_campos' => ['quantidade' => 3]]);
        // Sem resposta nenhuma: fica de fora (registro só de foto vai pro álbum da saída).
        VisitaRegistro::create(['visita_id' => $visita->id, 'tipo_registro_id' => $pesquisa->id]);

        Sanctum::actingAs(Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]));
        $formularios = collect($this->getJson('/api/atividades')->json('eventos'))->where('tipo_evento', 'FORMULARIO')->values();

        $this->assertCount(2, $formularios);
        $pesquisaNoFeed = $formularios->firstWhere('tipo_registro.id', $pesquisa->uuid);
        $this->assertSame("formulario:{$visitaUuid}:{$pesquisa->uuid}", $pesquisaNoFeed['id']);
        $this->assertSame([$item1->uuid, $item2->uuid], collect($pesquisaNoFeed['registros'])->pluck('id')->all());

        // Filtro por tipo vale pra qualquer tipo, não só alerta.
        $filtrado = collect($this->getJson("/api/atividades?tipo_registro_uuid={$pesquisa->uuid}")->json('eventos'));
        $this->assertCount(1, $filtrado);
        $this->assertSame('FORMULARIO', $filtrado[0]['tipo_evento']);
    }

    public function test_conversa_embutida_nao_marca_como_lido_e_comentario_nao_duplica_como_evento(): void
    {
        $empresa = Empresa::factory()->create();
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        $tipoAlerta = $this->criarTipoAlerta($empresa);
        $visitaUuid = $this->abrirVisita($promotor, $pdv);
        $registroUuid = $this->criarRegistro($visitaUuid, $tipoAlerta->uuid);
        $this->postJson("/api/visitas/{$visitaUuid}/registros/{$registroUuid}/comentarios", ['texto' => 'Chegou agora'])->assertCreated();

        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($admin);

        $eventos = collect($this->getJson('/api/atividades')->json('eventos'));
        $alerta = $eventos->firstWhere('tipo_evento', 'ALERTA');

        $this->assertSame('Chegou agora', $alerta['conversa'][0]['texto']);
        $this->assertTrue($alerta['conversa'][0]['novo']);
        $this->assertFalse($alerta['conversa'][0]['meu']);
        // A resposta aparece dentro do card, não como evento solto.
        $this->assertNull($eventos->firstWhere('tipo_evento', 'COMENTARIO'));

        // Ler o feed não marca como lido.
        $this->assertSame(1, $this->getJson('/api/atividades/resumo')->json('respostas_novas'));
        $alerta = collect($this->getJson('/api/atividades')->json('eventos'))->firstWhere('tipo_evento', 'ALERTA');
        $this->assertTrue($alerta['conversa'][0]['novo']);
    }

    public function test_filtro_com_foto_tira_chegada_e_registro_sem_foto(): void
    {
        $empresa = Empresa::factory()->create();
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        $tipoAlerta = $this->criarTipoAlerta($empresa);
        $visitaUuid = $this->abrirVisita($promotor, $pdv);
        $this->criarRegistro($visitaUuid, $tipoAlerta->uuid);

        Sanctum::actingAs(Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]));
        $this->getJson('/api/atividades?com_foto=1')->assertOk()->assertJsonCount(0, 'eventos');
    }

    public function test_resumo_traz_em_loja_alertas_sem_tratativa_e_parametro(): void
    {
        $empresa = Empresa::factory()->create();
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id, 'nome' => 'Carla']);
        $tipoAlerta = $this->criarTipoAlerta($empresa);
        $visitaUuid = $this->abrirVisita($promotor, $pdv);
        $comPlano = $this->criarRegistro($visitaUuid, $tipoAlerta->uuid);
        $semTratativa = $this->criarRegistro($visitaUuid, $tipoAlerta->uuid);

        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($admin);
        $this->postJson('/api/planos-acao', [
            'registro_uuid' => $comPlano, 'titulo' => 'Repor', 'etapas' => [['titulo' => 'Ligar']],
        ])->assertCreated();

        $resumo = $this->getJson('/api/atividades/resumo')->assertOk();

        $resumo->assertJsonPath('em_loja.0.usuario.nome', 'Carla')
            ->assertJsonPath('em_loja.0.visita_id', $visitaUuid)
            ->assertJsonPath('total_promotores', 1)
            // O alerta com plano ativo já está sendo tratado em Planos de Ação.
            ->assertJsonPath('alertas.total', 1)
            ->assertJsonPath('alertas.itens.0.registro_id', $semTratativa)
            ->assertJsonPath('requer_resolucao', false);

        Parametro::create(['empresa_id' => $empresa->id, 'chave' => 'ATIVIDADES_ALERTA_REQUER_RESOLUCAO', 'valor' => 'sim', 'ativo' => true]);
        $this->getJson('/api/atividades/resumo')->assertJsonPath('requer_resolucao', true);

        Sanctum::actingAs($promotor);
        $this->getJson('/api/atividades/resumo')->assertForbidden();
    }
}

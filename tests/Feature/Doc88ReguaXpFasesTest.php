<?php

namespace Tests\Feature;

use App\Enums\TipoCampoRegistro;
use App\Models\CampoTipoRegistro;
use App\Models\ClassePontoVenda;
use App\Models\MotivoNaoExecucao;
use App\Models\MotivoResolucaoAlerta;
use App\Models\Parametro;
use App\Models\PontoVenda;
use App\Models\Supervisor;
use App\Models\TipoRegistro;
use App\Models\Usuario;
use App\Models\VisitaRegistro;
use App\Models\XpLancamento;
use App\Models\XpLigaSemanal;
use App\Models\XpOcorrenciaClassificacao;
use App\Support\CalculadoraPontuacao;
use App\Support\Xp\LigaXp;
use App\Support\Xp\ReguaXp;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;

/**
 * docs/88 Fases 2 a 6 — perdas e ocorrências, iniciativa e avaliação, o lado do promotor, liga e
 * conquistas, planejamento e valor gerado.
 */
class Doc88ReguaXpFasesTest extends Doc88ReguaXpTest
{
    private function parametro(string $chave, string $valor): void
    {
        Parametro::withoutGlobalScopes()->updateOrCreate(['empresa_id' => $this->empresa->id, 'chave' => $chave], ['valor' => $valor, 'ativo' => true]);
    }

    private function formulario(array $extra = []): TipoRegistro
    {
        return TipoRegistro::withoutGlobalScopes()->create(['empresa_id' => $this->empresa->id, 'descricao' => 'Ponto extra', 'xp_iniciativa' => 80] + $extra);
    }

    private function registrar(string $visitaUuid, TipoRegistro $tipo, array $extra = []): string
    {
        return $this->postJson("/api/visitas/{$visitaUuid}/registros", ['tipo_registro_uuid' => $tipo->uuid] + $extra)
            ->assertCreated()->json('registro.id');
    }

    private function admin(): Usuario
    {
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $this->empresa->id]);
        Sanctum::actingAs($admin);

        return $admin;
    }

    // ---- Fase 3: iniciativa e avaliação ----

    public function test_iniciativa_pontua_com_teto_e_so_com_a_visita_completa(): void
    {
        $tipo = $this->formulario(['xp_teto_por_visita' => 2]);
        $visita = $this->visitar($this->os(), '12:00', ['obrigatorias_feitas' => 3, 'obrigatorias_total' => 3], function ($id) use ($tipo) {
            $this->registrar($id, $tipo);
            $this->registrar($id, $tipo);
            $this->registrar($id, $tipo);
        });
        $this->assertSame(160, (int) XpLancamento::where('visita_id', $visita->id)->where('categoria', 'iniciativa')->sum('pontos'));

        // Incompleta: o dever de casa vem antes do bônus.
        $incompleta = $this->visitar($this->os(), '12:00', ['obrigatorias_feitas' => 1, 'obrigatorias_total' => 3], fn ($id) => $this->registrar($id, $tipo));
        $this->assertSame(0, (int) XpLancamento::where('visita_id', $incompleta->id)->where('categoria', 'iniciativa')->sum('pontos'));
    }

    public function test_com_avaliacao_ligada_o_xp_espera_o_gestor_e_reprovado_nao_conta(): void
    {
        $this->parametro('AVALIACAO_FORMULARIO_HABILITADA', 'true');
        $tipo = $this->formulario(['exige_avaliacao' => true]);
        $registros = [];
        $visita = $this->visitar($this->os(), '12:00', ['obrigatorias_feitas' => 3, 'obrigatorias_total' => 3], function ($id) use ($tipo, &$registros) {
            $registros[] = $this->registrar($id, $tipo);
            $registros[] = $this->registrar($id, $tipo);
        });
        $this->assertSame('PENDENTE', VisitaRegistro::where('uuid', $registros[0])->value('avaliacao_status'));
        $this->assertSame(160, (int) XpLancamento::where('visita_id', $visita->id)->where('status', 'PENDENTE')->sum('pontos'));

        $gestor = $this->admin();
        $this->getJson('/api/avaliacoes-formulario')->assertOk()->assertJsonCount(2, 'registros');
        $this->postJson("/api/visitas/{$visita->uuid}/registros/{$registros[0]}/avaliar", ['decisao' => 'REPROVADO'])->assertStatus(422);
        $this->postJson("/api/visitas/{$visita->uuid}/registros/{$registros[0]}/avaliar", ['decisao' => 'APROVADO'])->assertOk();
        $this->postJson("/api/visitas/{$visita->uuid}/registros/{$registros[1]}/avaliar", ['decisao' => 'REPROVADO', 'motivo' => 'Foto não mostra o ponto extra'])->assertOk();

        $oficial = XpLancamento::where('visita_id', $visita->id)->where('categoria', 'iniciativa')->where('status', 'OFICIAL');
        $this->assertSame(80, (int) $oficial->sum('pontos'));
        // XP_COMPETENCIA padrão = APROVACAO: conta no dia da aprovação.
        $this->assertSame('2026-10-20', $oficial->first()->competencia->toDateString());
        $this->assertSame(0, (int) XpLancamento::where('visita_id', $visita->id)->where('status', 'PENDENTE')->sum('pontos'));
        $this->assertSame($gestor->id, VisitaRegistro::where('uuid', $registros[1])->value('avaliado_por_id'));
    }

    public function test_informacao_que_vira_acao_ganha_bonus(): void
    {
        $tipo = $this->formulario(['descricao' => 'Validade próxima', 'xp_iniciativa' => 10, 'xp_bonus_acao' => 30, 'eh_alerta' => true]);
        $registro = null;
        $visita = $this->visitar($this->os(), '12:00', ['obrigatorias_feitas' => 3, 'obrigatorias_total' => 3], function ($id) use ($tipo, &$registro) {
            $registro = $this->registrar($id, $tipo);
        });
        $this->admin();
        $procedente = MotivoResolucaoAlerta::withoutGlobalScopes()->create(['empresa_id' => $this->empresa->id, 'descricao' => 'Produto trocado', 'procedente' => true]);

        $this->postJson("/api/visitas/{$visita->uuid}/registros/{$registro}/resolver-alerta", ['motivo_uuid' => $procedente->uuid])->assertOk();

        $this->assertSame(30, (int) XpLancamento::where('visita_id', $visita->id)->where('categoria', 'bonus_acao')->sum('pontos'));
    }

    public function test_ruptura_nao_tira_ponto_da_qualidade(): void
    {
        $tipo = TipoRegistro::withoutGlobalScopes()->create(['empresa_id' => $this->empresa->id, 'descricao' => 'Loja perfeita', 'usa_pontuacao' => true]);
        CampoTipoRegistro::create(['tipo_registro_id' => $tipo->id, 'chave' => 'preco', 'rotulo' => 'Preço visível', 'tipo_campo' => TipoCampoRegistro::BOOLEANO, 'ordem' => 1]);
        CampoTipoRegistro::create(['tipo_registro_id' => $tipo->id, 'chave' => 'mix', 'rotulo' => 'Mix', 'tipo_campo' => TipoCampoRegistro::SORTIMENTO, 'ordem' => 2]);
        $tipo->load('campos');
        $valores = ['preco' => '1', 'mix' => json_encode(['presentes' => ['a'], 'ausentes' => ['b']])];

        // Compliance da loja continua contando a ruptura; o XP não.
        $this->assertSame(50, CalculadoraPontuacao::calcular($tipo, $valores));
        $this->assertSame(100, CalculadoraPontuacao::calcularParaXp($tipo, $valores));
        $tipo->eh_ruptura = true;
        $this->assertNull(CalculadoraPontuacao::calcularParaXp($tipo, $valores));
    }

    // ---- Fase 2: perdas, ocorrências e classificação ----

    public function test_regua_mostra_possivel_realizado_e_perdas_e_classificacao_e_append_only(): void
    {
        $this->visitar($this->os(), '13:20');
        $naoFeita = $this->os(['horario_previsto' => '15:00']);
        Carbon::setTestNow(Carbon::parse('2026-10-21 09:00', 'America/Sao_Paulo'));
        ReguaXp::fecharDia($this->empresa, '2026-10-20');

        $this->admin();
        $regua = $this->getJson('/api/xp/regua?dia=2026-10-20')->assertOk()->json('regua');
        $this->assertSame(1000, $regua['kpis']['possivel']);
        $this->assertSame(450, $regua['kpis']['realizado']);
        $this->assertSame(45, $regua['kpis']['aproveitamento']);
        $this->assertSame('visita_nao_realizada', $regua['perdas'][0]['categoria']);
        $this->assertTrue($regua['modo_previa']);
        $this->assertArrayHasKey('foto_url', $regua['promotores'][0]['usuario']);

        // Visão mensal (o fechamento da operação): o mês inteiro do dia escolhido.
        $mes = $this->getJson('/api/xp/regua?dia=2026-10-20&periodo=mes')->assertOk()->json('regua');
        $this->assertSame(['tipo' => 'mes', 'inicio' => '2026-10-01', 'fim' => '2026-10-31'], $mes['periodo']);
        $this->assertSame(1000, $mes['kpis']['possivel']);

        $ocorrencia = collect($this->getJson('/api/xp/ocorrencias?dia=2026-10-20')->assertOk()->json('ocorrencias'))
            ->firstWhere('categoria', 'visita_nao_realizada');
        $this->assertSame($naoFeita->uuid, $ocorrencia['ordem_servico_id']);

        $causa = MotivoNaoExecucao::withoutGlobalScopes()->create(['empresa_id' => $this->empresa->id, 'descricao' => 'Loja fechada']);
        $this->postJson("/api/xp/ocorrencias/{$ocorrencia['id']}/classificar", ['justificada' => false])->assertCreated();
        $this->postJson("/api/xp/ocorrencias/{$ocorrencia['id']}/classificar", ['justificada' => true, 'causa_uuid' => $causa->uuid, 'comentario' => 'Fecha toda terça de manhã'])
            ->assertCreated()
            ->assertJsonPath('ocorrencia.classificacao.justificada', true)
            ->assertJsonPath('ocorrencia.classificacao.causa.descricao', 'Loja fechada')
            // O número nunca é perdoado.
            ->assertJsonPath('ocorrencia.xp', 500);
        $this->assertSame(2, XpOcorrenciaClassificacao::count());
        // Justificada protege a ofensiva.
        $this->assertSame(1, \App\Models\XpResumoDiario::firstOrFail()->visitas_protegidas);
    }

    public function test_rotas_da_regua_exigem_o_recurso_de_plano_e_a_permissao(): void
    {
        $this->empresa->update(['controle_xp_habilitado' => false]);
        $admin = $this->admin();
        $this->getJson('/api/xp/regua')->assertForbidden();
        $this->getJson('/api/classes-ponto-venda')->assertForbidden();

        $this->empresa->update(['controle_xp_habilitado' => true]);
        Sanctum::actingAs($admin->fresh());
        $this->getJson('/api/xp/regua')->assertOk();
        Sanctum::actingAs(Usuario::factory()->gestor()->create(['empresa_id' => $this->empresa->id]));
        $this->getJson('/api/xp/regua')->assertForbidden();
    }

    // ---- Fase 1: classes de loja ----

    public function test_classes_padrao_unica_sugestao_e_aceite(): void
    {
        $this->admin();
        $soldado = $this->postJson('/api/classes-ponto-venda', ['nome' => 'Soldado', 'xp_base' => 50, 'duracao_padrao_minutos' => 15, 'eh_padrao' => true])
            ->assertCreated()->json('classe');
        $this->assertFalse($this->chefao->fresh()->eh_padrao);
        $this->deleteJson("/api/classes-ponto-venda/{$soldado['id']}")->assertStatus(422);

        // Loja com visitas longas: a rotina sugere Chefão; o gestor aceita.
        foreach (range(1, 3) as $i) {
            \App\Models\Visita::factory()->finalizada()->create([
                'empresa_id' => $this->empresa->id, 'ponto_venda_id' => $this->loja->id, 'usuario_id' => $this->promotor->id,
                'inicio_data' => now()->subDays($i)->setTime(9, 0), 'fim_data' => now()->subDays($i)->setTime(11, 30),
            ]);
        }
        $this->artisan('xp:sugerir-classes')->assertSuccessful();
        $sugestoes = $this->getJson('/api/classes-ponto-venda/sugestoes')->assertOk()->json('sugestoes');
        $this->assertSame('Chefão', $sugestoes[0]['sugerida']['nome']);
        $this->postJson('/api/classes-ponto-venda/sugestoes/aceitar', ['pontos_venda_uuids' => [$this->loja->uuid]])->assertOk()->assertJsonPath('atualizadas', 1);
        $this->assertSame($this->chefao->id, $this->loja->fresh()->classe_id);
    }

    public function test_superadmin_liga_o_recurso_e_ganha_classes_de_exemplo_e_parametros(): void
    {
        $outra = \App\Models\Empresa::factory()->create();
        Sanctum::actingAs(Usuario::factory()->create(['user_type' => 'SUPERADMIN', 'empresa_id' => null]));
        $this->putJson("/api/superadmin/empresas/{$outra->uuid}", ['controle_xp_habilitado' => true])
            ->assertOk()->assertJsonPath('empresa.controle_xp_habilitado', true);
        $this->assertSame(3, ClassePontoVenda::withoutGlobalScopes()->where('empresa_id', $outra->id)->count());

        // Configurações: a área da régua só aparece com o recurso ligado.
        Sanctum::actingAs(Usuario::factory()->admin()->create(['empresa_id' => $outra->id]));
        $areas = collect($this->getJson('/api/parametros/catalogo')->assertOk()->json('areas'))->pluck('chave');
        $this->assertContains('xp', $areas->all());
    }

    // ---- Fase 4 e 5: o lado do promotor, liga e conquistas ----

    public function test_promotor_so_ve_a_pontuacao_fora_do_modo_previa(): void
    {
        $this->visitar($this->os());
        $this->getJson('/api/xp/eu')->assertForbidden();

        $this->parametro('XP_VISIVEL_PROMOTOR', 'true');
        $hoje = $this->getJson('/api/xp/eu')->assertOk()->json('pontuacao');
        $this->assertSame(500, $hoje['plano']);
        $this->assertSame(500, $hoje['visitas'][0]['ganho']);
        $this->assertSame(1, $hoje['ofensiva']);

        $detalhe = $this->getJson("/api/xp/visitas/{$hoje['visitas'][0]['visita_id']}")->assertOk()->json('pontuacao');
        $this->assertSame(500, $detalhe['total']);
        $this->assertCount(6, $detalhe['partes']);

        $semana = $this->getJson('/api/xp/eu?periodo=semana')->assertOk()->json('pontuacao');
        $this->assertSame(500, $semana['total']);
        $this->assertTrue(collect($semana['conquistas'])->firstWhere('chave', 'loja_perfeita')['conquistada']);

        // EmpresaResource leva o que o app precisa pra mostrar ou esconder.
        $this->getJson('/api/auth/me')->assertOk()->assertJsonPath('usuario.empresa.xp.visivel_promotor', true);
    }

    public function test_liga_respeita_o_minimo_ordena_por_xp_e_fecha_a_semana(): void
    {
        $this->parametro('XP_VISIVEL_PROMOTOR', 'true');
        $this->parametro('XP_LIGA_MINIMO_VISITAS', '1');
        $supervisor = Supervisor::withoutGlobalScopes()->create(['empresa_id' => $this->empresa->id, 'codigo_externo' => 'S1', 'nome' => 'Marcos']);
        $this->promotor->update(['supervisor_id' => $supervisor->id]);
        $colega = Usuario::factory()->promotor()->create(['empresa_id' => $this->empresa->id, 'supervisor_id' => $supervisor->id, 'nome' => 'Ana']);
        $foraDaEquipe = Usuario::factory()->promotor()->create(['empresa_id' => $this->empresa->id]);

        $this->visitar($this->os());
        $this->os(['usuario_id' => $colega->id]);
        $this->os(['usuario_id' => $foraDaEquipe->id]);

        Sanctum::actingAs($this->promotor);
        $ranking = $this->getJson('/api/xp/ranking')->assertOk()->json('ranking');
        $this->assertSame('Equipe de Marcos', $ranking['grupo']);
        $this->assertSame(2, $ranking['participantes']);
        $this->assertSame(1, $ranking['eu']['posicao']);

        // Semana fechada não muda mais.
        LigaXp::fecharSemana($this->empresa, '2026-10-19');
        $this->assertSame(2, XpLigaSemanal::where('supervisor_id', $supervisor->id)->count());
        Carbon::setTestNow(Carbon::parse('2026-10-27 10:00', 'America/Sao_Paulo'));
        $this->getJson('/api/xp/ranking?semana=passada')->assertOk()->assertJsonPath('ranking.fechada', true)->assertJsonPath('ranking.eu.xp', 500);
    }

    public function test_liga_mensal_e_comparativo_mes_a_mes(): void
    {
        $this->parametro('XP_VISIVEL_PROMOTOR', 'true');
        $this->parametro('XP_LIGA_MINIMO_VISITAS', '1'); // mínimo mensal = 4
        $colega = Usuario::factory()->promotor()->create(['empresa_id' => $this->empresa->id, 'nome' => 'Ana']);
        foreach (range(1, 4) as $_) {
            $this->os(['usuario_id' => $colega->id]);
        }
        $this->visitar($this->os());
        $this->os();
        $this->os();
        $this->os();

        Sanctum::actingAs($this->promotor);
        $mes = $this->getJson('/api/xp/ranking?semana=mes')->assertOk()->json('ranking');
        $this->assertSame('mes', $mes['periodo']);
        $this->assertSame('2026-10-01', $mes['semana_inicio']);
        $this->assertSame(4, $mes['minimo_visitas']);
        $this->assertSame(2, $mes['participantes']);
        $this->assertSame(1, $mes['eu']['posicao']);
        $this->getJson('/api/xp/ranking?semana=mes_passado')->assertOk()->assertJsonPath('ranking.fechada', true);

        $this->admin();
        $comparativo = $this->getJson('/api/xp/evolucao?granularidade=mes')->assertOk()->json('comparativo');
        $this->assertCount(6, $comparativo['periodos']);
        $outubro = end($comparativo['periodos']);
        $this->assertSame('2026-10-01', $outubro['inicio']);
        $this->assertTrue($outubro['em_andamento']);
        $this->assertSame(500, $outubro['realizado']);
        // A colega ainda não tem resumo (nasce no fechamento do dia ou no check-out dela).
        $this->assertCount(1, $comparativo['promotores']);

        // Semana e dia, e o filtro por promotor.
        $this->assertCount(8, $this->getJson('/api/xp/evolucao?granularidade=semana')->assertOk()->json('comparativo.periodos'));
        $dias = $this->getJson("/api/xp/evolucao?granularidade=dia&usuario_uuid={$this->promotor->uuid}")->assertOk()->json('comparativo');
        $this->assertCount(14, $dias['periodos']);
        $this->assertSame('2026-10-20', end($dias['periodos'])['inicio']);
        $this->assertCount(1, $dias['promotores']);
        $this->assertSame(500, end($dias['periodos'])['realizado']);
    }

    // ---- Fase 6: planejamento e valor gerado ----

    public function test_planejamento_e_valor_gerado(): void
    {
        $tipo = $this->formulario();
        $this->visitar($this->os(), '12:00', ['obrigatorias_feitas' => 3, 'obrigatorias_total' => 3], fn ($id) => $this->registrar($id, $tipo));
        $this->os(['horario_previsto' => null]);

        $this->admin();
        $plano = $this->getJson('/api/xp/planejamento?dia=2026-10-20')->assertOk()->json('planejamento');
        $this->assertSame(2, $plano['os_total']);
        $this->assertSame(50, $plano['os_com_dia_e_horario']);

        $valor = $this->getJson('/api/xp/valor-gerado?mes=2026-10')->assertOk()->json('valor_gerado');
        $this->assertSame(1, $valor['total_registros']);
        $this->assertSame('Ponto extra', $valor['formularios'][0]['formulario']['descricao']);
    }
}

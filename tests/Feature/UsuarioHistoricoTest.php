<?php

namespace Tests\Feature;

use App\Models\Empresa;
use App\Models\PontoVenda;
use App\Models\TipoRegistro;
use App\Models\Usuario;
use App\Models\UsuarioLoginLog;
use App\Models\Visita;
use App\Models\VisitaRegistro;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * docs/02-API-BACKEND.md#usuários — GET /usuarios/{uuid}/historico junta login, visita e
 * registro numa linha do tempo só. Ver UsuarioController::historico.
 */
class UsuarioHistoricoTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_bem_sucedido_grava_log(): void
    {
        $empresa = Empresa::factory()->create();
        $promotor = Usuario::factory()->promotor()->create([
            'empresa_id' => $empresa->id,
            'senha_hash' => bcrypt('senha12345'),
        ]);

        $this->postJson('/api/auth/login', [
            'email' => $promotor->email,
            'senha' => 'senha12345',
            'dispositivo_identificador' => 'aparelho-teste',
            'dispositivo_nome' => 'Moto G',
        ])->assertOk();

        $this->assertDatabaseHas('usuario_login_logs', [
            'usuario_id' => $promotor->id,
            'dispositivo_identificador' => 'aparelho-teste',
            'dispositivo_nome' => 'Moto G',
        ]);
    }

    public function test_historico_junta_login_visita_e_registro_ordenado_por_data_desc(): void
    {
        $empresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);

        // created_at/updated_at não são fillable (não fazem sentido como entrada de usuário) —
        // forceFill pra simular um log antigo neste teste, já que create() ignoraria os dois
        // silenciosamente e o registro nasceria com o "agora" real do teste.
        UsuarioLoginLog::create([
            'usuario_id' => $promotor->id,
            'dispositivo_identificador' => 'aparelho-1',
        ])->forceFill(['created_at' => now()->subHours(3), 'updated_at' => now()->subHours(3)])->save();

        $visita = Visita::create([
            'empresa_id' => $empresa->id,
            'ponto_venda_id' => $pdv->id,
            'usuario_id' => $promotor->id,
            'status' => 'FINALIZADA',
            'inicio_data' => now()->subHours(2),
            'inicio_latitude' => $pdv->latitude,
            'inicio_longitude' => $pdv->longitude,
            'inicio_distancia_metros' => 0,
            'fim_data' => now()->subHour(),
            'fim_latitude' => $pdv->latitude,
            'fim_longitude' => $pdv->longitude,
            'fim_distancia_metros' => 0,
        ]);

        $tipoObservacao = TipoRegistro::withoutGlobalScopes()
            ->where('empresa_id', $empresa->id)->where('descricao', 'Observação')->first();

        VisitaRegistro::create([
            'visita_id' => $visita->id,
            'tipo_registro_id' => $tipoObservacao->id,
            'observacao' => 'Gôndola reorganizada',
        ])->forceFill(['created_at' => now()->subMinutes(30), 'updated_at' => now()->subMinutes(30)])->save();

        Sanctum::actingAs($admin);

        $response = $this->getJson("/api/usuarios/{$promotor->uuid}/historico");

        $response->assertOk();
        $tipos = collect($response->json('eventos'))->pluck('tipo')->all();

        // Mais recente primeiro: registro > fim da visita > início da visita > login.
        $this->assertSame(['REGISTRO', 'VISITA_FIM', 'VISITA_INICIO', 'LOGIN'], $tipos);
        $this->assertSame('Gôndola reorganizada', $response->json('eventos.0.observacao'));
        $this->assertSame($pdv->uuid, $response->json('eventos.1.ponto_venda.id'));
        $this->assertSame(4, $response->json('meta.total'));
    }

    public function test_historico_filtra_por_tipos_e_traz_localizacao_nos_eventos_de_visita(): void
    {
        $empresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        $pdv = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);

        UsuarioLoginLog::create(['usuario_id' => $promotor->id, 'dispositivo_identificador' => 'aparelho-1']);

        Visita::create([
            'empresa_id' => $empresa->id,
            'ponto_venda_id' => $pdv->id,
            'usuario_id' => $promotor->id,
            'status' => 'FINALIZADA',
            'inicio_data' => now()->subHour(),
            'inicio_latitude' => -3.1,
            'inicio_longitude' => -60.02,
            'inicio_distancia_metros' => 12.5,
            'fim_data' => now()->subMinutes(40),
            'fim_latitude' => -3.101,
            'fim_longitude' => -60.021,
            'fim_distancia_metros' => 8.3,
        ]);

        Sanctum::actingAs($admin);

        $response = $this->getJson("/api/usuarios/{$promotor->uuid}/historico?tipos=VISITA_INICIO,VISITA_FIM")->assertOk();

        $tipos = collect($response->json('eventos'))->pluck('tipo')->all();
        $this->assertSame(['VISITA_FIM', 'VISITA_INICIO'], $tipos);
        // inicio_latitude/distancia_metros vêm sem cast numérico no model (mesmo padrão de
        // VisitaResource) — comparação tolerante ao tipo (string ou float, conforme o driver).
        $this->assertEquals(-3.1, $response->json('eventos.1.latitude'));
        $this->assertEquals(12.5, $response->json('eventos.1.distancia_metros'));
    }

    public function test_historico_filtra_por_periodo(): void
    {
        $empresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);

        UsuarioLoginLog::create(['usuario_id' => $promotor->id, 'dispositivo_identificador' => 'antigo'])
            ->forceFill(['created_at' => now()->subDays(10)])->save();
        UsuarioLoginLog::create(['usuario_id' => $promotor->id, 'dispositivo_identificador' => 'recente'])
            ->forceFill(['created_at' => now()->subDay()])->save();

        Sanctum::actingAs($admin);

        $response = $this->getJson(
            "/api/usuarios/{$promotor->uuid}/historico?data_inicio=".now()->subDays(2)->toDateString(),
        )->assertOk();

        $this->assertSame(1, $response->json('meta.total'));
        $this->assertSame('recente', $response->json('eventos.0.dispositivo'));
    }

    public function test_promotor_nao_acessa_historico_de_outro_usuario(): void
    {
        $empresa = Empresa::factory()->create();
        $promotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        $outroPromotor = Usuario::factory()->promotor()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($promotor);

        $this->getJson("/api/usuarios/{$outroPromotor->uuid}/historico")->assertForbidden();
    }
}

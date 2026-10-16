<?php

namespace Tests\Feature;

use App\Models\Contrato;
use App\Models\Empresa;
use App\Models\PontoVenda;
use App\Models\TipoRegistro;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * docs/66-GERAR-OS-AO-SALVAR-FORMULARIO.md — "Enviar agora pro app": o formulário abre um
 * Direcionamento já preenchido; a geração é a do Direcionamento (síncrona no store).
 */
class Doc66EnvioImediatoTest extends TestCase
{
    use RefreshDatabase;

    private Empresa $empresa;

    private TipoRegistro $formulario;

    protected function setUp(): void
    {
        parent::setUp();
        // Meio-dia fixo: o fim da vigência é endOfDay(), então "agora + 1h" não pode virar o dia.
        $this->travelTo(now()->setTime(12, 0));
        $this->empresa = Empresa::factory()->create();
        $this->formulario = TipoRegistro::create(['empresa_id' => $this->empresa->id, 'descricao' => 'Auditoria de expositor']);
        Sanctum::actingAs(Usuario::factory()->admin()->create(['empresa_id' => $this->empresa->id]));
    }

    private function direcionamento(string $inicio): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/direcionamentos', [
            'descricao' => 'Auditoria de expositor — envio imediato',
            'vigencia_inicio' => $inicio,
            'vigencia_fim' => now()->endOfDay()->toDateTimeString(),
            'formularios' => [['tipo_registro_uuid' => $this->formulario->uuid, 'obrigatorio' => true, 'calcula_percentual_compliance' => false]],
        ]);
    }

    public function test_inicio_agora_gera_as_os_na_hora(): void
    {
        PontoVenda::factory()->count(3)->create(['empresa_id' => $this->empresa->id]);

        // O diálogo manda "agora menos 1 minuto" pra folga de relógio entre navegador e servidor.
        $this->direcionamento(now()->subMinute()->toDateTimeString())
            ->assertCreated()
            ->assertJsonPath('progresso.ordens_geradas', 3);
    }

    public function test_inicio_no_futuro_nao_gera_nada_agora(): void
    {
        PontoVenda::factory()->count(2)->create(['empresa_id' => $this->empresa->id]);

        // §4.1: é o motivo de o diálogo avisar quando o início escolhido fica à frente do relógio.
        $this->direcionamento(now()->addHour()->toDateTimeString())
            ->assertCreated()
            ->assertJsonPath('progresso.ordens_geradas', 0);
    }

    public function test_lista_so_lojas_com_contrato_ativo(): void
    {
        $comContrato = PontoVenda::factory()->create(['empresa_id' => $this->empresa->id, 'fantasia' => 'Com contrato']);
        $contratoInativo = PontoVenda::factory()->create(['empresa_id' => $this->empresa->id, 'fantasia' => 'Contrato inativo']);
        PontoVenda::factory()->create(['empresa_id' => $this->empresa->id, 'fantasia' => 'Sem contrato']);
        Contrato::factory()->create(['empresa_id' => $this->empresa->id, 'ponto_venda_id' => $comContrato->id, 'ativo' => true]);
        Contrato::factory()->create(['empresa_id' => $this->empresa->id, 'ponto_venda_id' => $contratoInativo->id, 'ativo' => false]);

        $nomes = collect($this->getJson('/api/pontos-venda?com_contrato_ativo=1&por_pagina=200')->assertOk()->json('pontos_venda'))->pluck('fantasia')->all();

        $this->assertSame(['Com contrato'], $nomes);
        $this->assertCount(3, $this->getJson('/api/pontos-venda?por_pagina=200')->json('pontos_venda'));
    }
}

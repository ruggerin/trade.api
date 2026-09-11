<?php

namespace Tests\Feature\PontoVenda;

use App\Models\Contrato;
use App\Models\Empresa;
use App\Models\PontoVenda;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * `tem_contrato_ativo` — booleano computado pro app mobile saber se deve mostrar a Ação de
 * escopo CONTRATO (ver App\Enums\EscopoAcaoTipoRegistro), sem expor nenhum dado do contrato em
 * si (isso continua só no admin web, ver docs/04-APP-MOBILE.md).
 */
class TemContratoAtivoTest extends TestCase
{
    use RefreshDatabase;

    public function test_pdv_sem_contrato_retorna_false(): void
    {
        $empresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        $pontoVenda = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($admin);

        $this->getJson('/api/pontos-venda')
            ->assertOk()
            ->assertJsonPath('pontos_venda.0.tem_contrato_ativo', false);

        $this->getJson("/api/pontos-venda/{$pontoVenda->uuid}")
            ->assertOk()
            ->assertJsonPath('ponto_venda.tem_contrato_ativo', false);
    }

    public function test_pdv_com_contrato_ativo_retorna_true(): void
    {
        $empresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        $pontoVenda = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        Contrato::factory()->create(['empresa_id' => $empresa->id, 'ponto_venda_id' => $pontoVenda->id, 'ativo' => true]);
        Sanctum::actingAs($admin);

        $this->getJson('/api/pontos-venda')
            ->assertOk()
            ->assertJsonPath('pontos_venda.0.tem_contrato_ativo', true);
    }

    public function test_pdv_com_contrato_inativo_retorna_false(): void
    {
        $empresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        $pontoVenda = PontoVenda::factory()->create(['empresa_id' => $empresa->id]);
        Contrato::factory()->create(['empresa_id' => $empresa->id, 'ponto_venda_id' => $pontoVenda->id, 'ativo' => false]);
        Sanctum::actingAs($admin);

        $this->getJson('/api/pontos-venda')
            ->assertOk()
            ->assertJsonPath('pontos_venda.0.tem_contrato_ativo', false);
    }
}

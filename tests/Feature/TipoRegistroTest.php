<?php

namespace Tests\Feature;

use App\Models\CampanhaAuditoria;
use App\Models\CampoTipoRegistro;
use App\Models\Empresa;
use App\Models\TipoRegistro;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * docs/02-API-BACKEND.md#tipos-de-registro — catálogo customizável por empresa que substitui o
 * antigo enum fixo TipoRegistroVisita. Mesma permissão de catálogo (catalogo.gerenciar).
 */
class TipoRegistroTest extends TestCase
{
    use RefreshDatabase;

    public function test_empresa_nasce_com_os_3_tipos_de_fabrica(): void
    {
        $empresa = Empresa::factory()->create();

        $descricoes = TipoRegistro::where('empresa_id', $empresa->id)->pluck('descricao')->sort()->values()->all();

        $this->assertSame(['Foto', 'Observação', 'Ruptura'], $descricoes);
    }

    public function test_admin_cria_tipo_de_registro_com_campos_customizados(): void
    {
        $empresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/tipos-registro', [
            'descricao' => 'Ponto extra',
            'exige_foto' => true,
            'permite_vincular_catalogo' => true,
            'campos' => [
                ['chave' => 'quantidade', 'rotulo' => 'Quantidade', 'tipo_campo' => 'NUMERO', 'obrigatorio' => true],
                ['chave' => 'valor', 'rotulo' => 'Valor', 'tipo_campo' => 'MOEDA', 'obrigatorio' => false],
            ],
        ]);

        $response->assertCreated()
            ->assertJsonPath('tipo_registro.descricao', 'Ponto extra')
            ->assertJsonCount(2, 'tipo_registro.campos')
            ->assertJsonPath('tipo_registro.campos.0.chave', 'quantidade');
    }

    public function test_chave_de_campo_invalida_retorna_422(): void
    {
        $empresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($admin);

        $this->postJson('/api/tipos-registro', [
            'descricao' => 'Ponto extra',
            'campos' => [
                ['chave' => 'Quantidade Total', 'rotulo' => 'Quantidade', 'tipo_campo' => 'NUMERO'],
            ],
        ])->assertStatus(422)->assertJsonValidationErrors('campos.0.chave');
    }

    public function test_multipla_escolha_sem_opcoes_retorna_422(): void
    {
        $empresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($admin);

        $this->postJson('/api/tipos-registro', [
            'descricao' => 'Ação da concorrência',
            'campos' => [
                ['chave' => 'estado', 'rotulo' => 'Estado', 'tipo_campo' => 'MULTIPLA_ESCOLHA'],
            ],
        ])->assertStatus(422)->assertJsonValidationErrors('campos.0.opcoes');
    }

    public function test_update_substitui_a_lista_de_campos_inteira(): void
    {
        $empresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        $tipo = TipoRegistro::create(['empresa_id' => $empresa->id, 'descricao' => 'Ponto extra']);
        CampoTipoRegistro::create([
            'tipo_registro_id' => $tipo->id, 'chave' => 'antigo', 'rotulo' => 'Antigo', 'tipo_campo' => 'TEXTO', 'ordem' => 0,
        ]);
        Sanctum::actingAs($admin);

        $response = $this->putJson("/api/tipos-registro/{$tipo->uuid}", [
            'campos' => [
                ['chave' => 'novo', 'rotulo' => 'Novo', 'tipo_campo' => 'NUMERO', 'obrigatorio' => true],
            ],
        ]);

        $response->assertOk()->assertJsonCount(1, 'tipo_registro.campos');
        $this->assertDatabaseMissing('campos_tipo_registro', ['chave' => 'antigo']);
        $this->assertDatabaseHas('campos_tipo_registro', ['chave' => 'novo', 'tipo_registro_id' => $tipo->id]);
    }

    public function test_gestor_sem_permissao_de_catalogo_e_bloqueado(): void
    {
        $empresa = Empresa::factory()->create();
        $gestor = Usuario::factory()->gestor()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($gestor);

        $this->postJson('/api/tipos-registro', ['descricao' => 'Ponto extra'])->assertForbidden();
    }

    public function test_desativar_e_soft_delete(): void
    {
        $empresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        $tipo = TipoRegistro::create(['empresa_id' => $empresa->id, 'descricao' => 'Ponto extra']);
        Sanctum::actingAs($admin);

        $this->deleteJson("/api/tipos-registro/{$tipo->uuid}")->assertNoContent();
        $this->assertDatabaseHas('tipos_registro', ['id' => $tipo->id, 'ativo' => false]);
    }

    /**
     * Ação obrigatória — ver App\Enums\EscopoAcaoTipoRegistro. Aparece na aba Ações da visita em
     * vez de só uma opção do Registro geral.
     */
    public function test_cria_acao_obrigatoria_com_escopo_sempre(): void
    {
        $empresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/tipos-registro', [
            'descricao' => 'Checklist de organização',
            'acao_obrigatoria' => true,
            'escopo_acao' => 'SEMPRE',
        ]);

        $response->assertCreated()
            ->assertJsonPath('tipo_registro.acao_obrigatoria', true)
            ->assertJsonPath('tipo_registro.escopo_acao', 'SEMPRE')
            ->assertJsonPath('tipo_registro.campanha_auditoria_uuid', null);
    }

    public function test_escopo_campanha_sem_campanha_uuid_retorna_422(): void
    {
        $empresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($admin);

        $this->postJson('/api/tipos-registro', [
            'descricao' => 'Auditar tabloide da campanha',
            'acao_obrigatoria' => true,
            'escopo_acao' => 'CAMPANHA',
        ])->assertStatus(422)->assertJsonValidationErrors('campanha_auditoria_uuid');
    }

    public function test_acao_obrigatoria_sem_escopo_retorna_422(): void
    {
        $empresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($admin);

        $this->postJson('/api/tipos-registro', [
            'descricao' => 'Auditar contrato de expositor',
            'acao_obrigatoria' => true,
        ])->assertStatus(422)->assertJsonValidationErrors('escopo_acao');
    }

    public function test_cria_acao_vinculada_a_campanha_de_outra_empresa_retorna_422(): void
    {
        $empresa = Empresa::factory()->create();
        $outraEmpresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        $campanha = CampanhaAuditoria::factory()->create(['empresa_id' => $outraEmpresa->id]);
        Sanctum::actingAs($admin);

        $this->postJson('/api/tipos-registro', [
            'descricao' => 'Auditar tabloide da campanha',
            'acao_obrigatoria' => true,
            'escopo_acao' => 'CAMPANHA',
            'campanha_auditoria_uuid' => $campanha->uuid,
        ])->assertStatus(422)->assertJsonValidationErrors('campanha_auditoria_uuid');
    }

    public function test_cria_acao_vinculada_a_campanha_da_propria_empresa(): void
    {
        $empresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        $campanha = CampanhaAuditoria::factory()->create(['empresa_id' => $empresa->id]);
        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/tipos-registro', [
            'descricao' => 'Auditar tabloide da campanha',
            'acao_obrigatoria' => true,
            'escopo_acao' => 'CAMPANHA',
            'campanha_auditoria_uuid' => $campanha->uuid,
        ]);

        $response->assertCreated()->assertJsonPath('tipo_registro.campanha_auditoria_uuid', $campanha->uuid);
    }

    public function test_mudar_escopo_pra_fora_de_campanha_desvincula_a_campanha(): void
    {
        $empresa = Empresa::factory()->create();
        $admin = Usuario::factory()->admin()->create(['empresa_id' => $empresa->id]);
        $campanha = CampanhaAuditoria::factory()->create(['empresa_id' => $empresa->id]);
        $tipo = TipoRegistro::create([
            'empresa_id' => $empresa->id,
            'descricao' => 'Auditar tabloide',
            'acao_obrigatoria' => true,
            'escopo_acao' => 'CAMPANHA',
            'campanha_auditoria_id' => $campanha->id,
        ]);
        Sanctum::actingAs($admin);

        $response = $this->putJson("/api/tipos-registro/{$tipo->uuid}", ['escopo_acao' => 'SEMPRE']);

        $response->assertOk()->assertJsonPath('tipo_registro.campanha_auditoria_uuid', null);
        $this->assertDatabaseHas('tipos_registro', ['id' => $tipo->id, 'campanha_auditoria_id' => null]);
    }
}

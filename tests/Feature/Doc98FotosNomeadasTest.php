<?php

namespace Tests\Feature;

use App\Enums\StatusVisita;
use App\Models\Empresa;
use App\Models\ImagemRegistro;
use App\Models\Parametro;
use App\Models\PontoVenda;
use App\Models\TipoRegistro;
use App\Models\Usuario;
use App\Models\Visita;
use App\Models\VisitaRegistro;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Fotos nomeadas no formulário (docs/98): pergunta FOTO (antes/depois), captura com hora e GPS,
 * galeria Horus (reaproveitar desta visita e de visitas anteriores na loja) e alertas.
 */
class Doc98FotosNomeadasTest extends TestCase
{
    use RefreshDatabase;

    private Empresa $empresa;

    private Usuario $admin;

    private Usuario $promotor;

    private PontoVenda $loja;

    private string $visitaUuid;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->empresa = Empresa::factory()->create();
        $this->admin = Usuario::factory()->admin()->create(['empresa_id' => $this->empresa->id]);
        $this->promotor = Usuario::factory()->promotor()->create(['empresa_id' => $this->empresa->id]);
        $this->loja = PontoVenda::factory()->create(['empresa_id' => $this->empresa->id, 'latitude' => -3.1, 'longitude' => -60.0]);
    }

    // GD não está habilitado neste ambiente — PNG 1x1 real, como em FotoDepoisDoRegistroTest.
    private function imagem(): UploadedFile
    {
        $caminho = tempnam(sys_get_temp_dir(), 'foto').'.png';
        file_put_contents($caminho, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII='));

        return new UploadedFile($caminho, 'foto.png', 'image/png', null, true);
    }

    /** "Conquista de espaço": Antes (obrigatória, 1–2, histórico) · módulos · Depois (obrigatória, até 3, só desta visita). */
    private function criarConquista(array $antes = []): string
    {
        Sanctum::actingAs($this->admin);

        return $this->postJson('/api/tipos-registro', [
            'descricao' => 'Conquista de espaço',
            'campos' => [
                ['chave' => 'antes', 'rotulo' => 'Antes', 'tipo_campo' => 'FOTO', 'obrigatorio' => true,
                    'foto_min' => 1, 'foto_max' => 2, 'foto_reaproveitar' => 'HISTORICO', 'foto_dica' => 'Enquadre a gôndola inteira', ...$antes],
                ['chave' => 'modulos', 'rotulo' => 'Quantos módulos?', 'tipo_campo' => 'NUMERO', 'obrigatorio' => false],
                ['chave' => 'depois', 'rotulo' => 'Depois', 'tipo_campo' => 'FOTO', 'obrigatorio' => true,
                    'foto_max' => 3, 'foto_reaproveitar' => 'VISITA'],
            ],
        ])->assertCreated()->json('tipo_registro.id');
    }

    private function abrirVisita(): void
    {
        Sanctum::actingAs($this->promotor);
        $this->visitaUuid = $this->postJson('/api/visitas', [
            'ponto_venda_uuid' => $this->loja->uuid, 'latitude' => -3.1, 'longitude' => -60.0,
        ])->assertCreated()->json('visita.id');
    }

    private function registrar(string $tipo, array $extra = [])
    {
        return $this->postJson("/api/visitas/{$this->visitaUuid}/registros", ['tipo_registro_uuid' => $tipo] + $extra);
    }

    private function enviarFoto(string $registro, array $dados)
    {
        return $this->post("/api/visitas/{$this->visitaUuid}/registros/{$registro}/imagens", ['imagem' => $this->imagem()] + $dados, ['Accept' => 'application/json']);
    }

    /** Foto de uma visita anterior nesta loja (ou em outra), já finalizada, ligada a um registro. */
    private function fotoAnterior(?PontoVenda $loja = null, string $quando = '-3 days'): ImagemRegistro
    {
        $tipo = TipoRegistro::withoutGlobalScopes()->where('empresa_id', $this->empresa->id)->where('descricao', 'Foto')->firstOrFail();
        $visita = Visita::factory()->create([
            'empresa_id' => $this->empresa->id,
            'usuario_id' => Usuario::factory()->promotor()->create(['empresa_id' => $this->empresa->id])->id,
            'ponto_venda_id' => ($loja ?? $this->loja)->id,
            'status' => StatusVisita::FINALIZADA,
            'inicio_data' => Carbon::parse($quando),
            'fim_data' => Carbon::parse($quando)->addHour(),
        ]);
        $registro = VisitaRegistro::create(['visita_id' => $visita->id, 'tipo_registro_id' => $tipo->id]);
        Storage::disk('local')->put("visitas/{$visita->id}/antiga.png", 'x');
        $imagem = ImagemRegistro::create([
            'visita_id' => $visita->id, 'caminho' => "visitas/{$visita->id}/antiga.png", 'capturada_em' => Carbon::parse($quando),
        ]);
        $registro->imagens()->attach($imagem->id, ['ordem' => 0]);

        return $imagem;
    }

    public function test_editor_salva_e_devolve_a_configuracao_da_pergunta_foto(): void
    {
        $uuid = $this->criarConquista(['foto_max' => 1, 'foto_min' => 3]);

        $campos = collect($this->getJson("/api/tipos-registro/{$uuid}")->assertOk()->json('tipo_registro.campos'))->keyBy('chave');
        $this->assertSame('FOTO', $campos['antes']['tipo_campo']);
        $this->assertSame(3, $campos['antes']['foto_min']);
        // Máximo abaixo do mínimo sobe pro mínimo: a pergunta nunca fica impossível.
        $this->assertSame(3, $campos['antes']['foto_max']);
        $this->assertSame('HISTORICO', $campos['antes']['foto_reaproveitar']);
        $this->assertSame('Enquadre a gôndola inteira', $campos['antes']['foto_dica']);
        $this->assertSame('VISITA', $campos['depois']['foto_reaproveitar']);
        // Pergunta que não é FOTO não carrega configuração de foto.
        $this->assertNull($campos['modulos']['foto_max']);
        $this->assertSame('NAO', $campos['modulos']['foto_reaproveitar']);
    }

    public function test_pergunta_foto_obrigatoria_cobra_quantidade_minimo_e_maximo(): void
    {
        $tipo = $this->criarConquista();
        $this->abrirVisita();

        $this->registrar($tipo, ['imagens_por_campo' => ['antes' => 1]])
            ->assertStatus(422)->assertJsonValidationErrors('imagens_por_campo.depois');
        $this->registrar($tipo, ['imagens_por_campo' => ['antes' => 3, 'depois' => 1], 'imagens_a_enviar' => 4])
            ->assertStatus(422)->assertJsonValidationErrors('imagens_por_campo.antes');

        $this->registrar($tipo, ['imagens_por_campo' => ['antes' => 1, 'depois' => 2], 'imagens_a_enviar' => 3, 'valores_campos' => ['modulos' => '2', 'antes' => 'lixo']])
            ->assertCreated()
            // A pergunta FOTO não guarda resposta: as fotos vão pra pivô.
            ->assertJsonMissingPath('registro.valores_campos.antes');
    }

    public function test_produto_em_ruptura_dispensa_as_fotos_obrigatorias(): void
    {
        $tipo = $this->criarConquista();
        $this->abrirVisita();

        $this->registrar($tipo, ['imagens_por_campo' => [], 'ruptura' => true])->assertCreated();
    }

    public function test_app_antigo_sem_contagem_por_pergunta_nao_e_travado(): void
    {
        $tipo = $this->criarConquista();
        $this->abrirVisita();

        $this->registrar($tipo, ['imagens_a_enviar' => 1])->assertCreated();
    }

    public function test_foto_enviada_guarda_pergunta_origem_e_captura(): void
    {
        $tipo = $this->criarConquista();
        $this->abrirVisita();
        $registro = $this->registrar($tipo, ['imagens_por_campo' => ['antes' => 1, 'depois' => 1], 'imagens_a_enviar' => 2])->json('registro.id');

        $resposta = $this->enviarFoto($registro, [
            'idempotency_key' => 'r1:antes:0', 'campo_chave' => 'antes', 'origem' => 'CAMERA',
            'capturada_em' => now()->subMinutes(30)->toIso8601String(), 'latitude' => -3.1, 'longitude' => -60.0, 'precisao_metros' => 8.4,
        ])->assertCreated();

        $foto = $resposta->json('registro.imagens.0');
        $this->assertSame('antes', $foto['campo_chave']);
        $this->assertSame('CAMERA', $foto['origem']);
        $this->assertNotNull($foto['capturada_em']);
        $this->assertSame(0, $foto['distancia_loja_metros']);
        $this->assertFalse($foto['de_outra_visita']);
        $this->assertSame([['chave' => 'antes', 'rotulo' => 'Antes'], ['chave' => 'depois', 'rotulo' => 'Depois']], $resposta->json('registro.perguntas_foto'));
        $this->assertSame(8, ImagemRegistro::where('uuid', $foto['id'])->value('precisao_metros'));

        // Hora do aparelho no futuro é cortada na hora do servidor.
        $this->enviarFoto($registro, ['idempotency_key' => 'r1:depois:0', 'campo_chave' => 'depois', 'capturada_em' => now()->addDay()->toIso8601String()])
            ->assertCreated();
        $this->assertTrue(ImagemRegistro::where('idempotency_key', 'r1:depois:0')->first()->capturada_em->lte(now()));
    }

    public function test_foto_numa_pergunta_que_nao_e_foto_e_recusada(): void
    {
        $tipo = $this->criarConquista();
        $this->abrirVisita();
        $registro = $this->registrar($tipo, ['imagens_a_enviar' => 1])->json('registro.id');

        $this->enviarFoto($registro, ['campo_chave' => 'modulos'])->assertStatus(422);
        $this->enviarFoto($registro, ['campo_chave' => 'nao-existe'])->assertStatus(422);
    }

    public function test_reaproveitar_foto_desta_visita_nao_duplica_o_arquivo(): void
    {
        $tipo = $this->criarConquista();
        $this->abrirVisita();
        $primeiro = $this->registrar($tipo, ['imagens_a_enviar' => 1])->json('registro.id');
        $this->enviarFoto($primeiro, ['idempotency_key' => 'foto-local-1'])->assertCreated();

        // Outro registro usa a mesma foto (mesma chave do aparelho) como "Antes".
        $segundo = $this->registrar($tipo, ['imagens_por_campo' => ['antes' => 1, 'depois' => 1], 'imagens_a_enviar' => 2])->json('registro.id');
        $foto = $this->enviarFoto($segundo, ['idempotency_key' => 'foto-local-1', 'campo_chave' => 'antes', 'origem' => 'VISITA'])
            ->assertCreated()->json('registro.imagens.0');

        $this->assertSame('VISITA', $foto['origem']);
        $this->assertSame(1, ImagemRegistro::where('idempotency_key', 'foto-local-1')->count());
    }

    public function test_reaproveitar_foto_de_visita_anterior_na_mesma_loja(): void
    {
        $tipo = $this->criarConquista();
        $anterior = $this->fotoAnterior();
        $this->abrirVisita();

        $resposta = $this->registrar($tipo, [
            'imagens_por_campo' => ['depois' => 1], 'imagens_a_enviar' => 1,
            'imagens_historico' => [['uuid' => $anterior->uuid, 'campo_chave' => 'antes']],
        ])->assertCreated();

        $foto = $resposta->json('registro.imagens.0');
        $this->assertSame($anterior->uuid, $foto['id']);
        $this->assertSame('antes', $foto['campo_chave']);
        $this->assertSame('HISTORICO', $foto['origem']);
        $this->assertTrue($foto['de_outra_visita']);
        // O arquivo abre pela visita que reaproveitou (o promotor não é dono da visita antiga).
        $this->get($foto['url'])->assertOk();
    }

    public function test_foto_anterior_de_outra_loja_fora_da_janela_ou_em_pergunta_sem_historico_e_recusada(): void
    {
        $tipo = $this->criarConquista();
        $outraLoja = $this->fotoAnterior(PontoVenda::factory()->create(['empresa_id' => $this->empresa->id]));
        $antiga = $this->fotoAnterior(null, '-90 days');
        $valida = $this->fotoAnterior();
        $this->abrirVisita();
        $base = ['imagens_por_campo' => ['depois' => 1], 'imagens_a_enviar' => 1];

        $this->registrar($tipo, $base + ['imagens_historico' => [['uuid' => $outraLoja->uuid, 'campo_chave' => 'antes']]])
            ->assertStatus(422)->assertJsonValidationErrors('imagens_por_campo.antes');
        $this->registrar($tipo, $base + ['imagens_historico' => [['uuid' => $antiga->uuid, 'campo_chave' => 'antes']]])
            ->assertStatus(422);
        $this->registrar($tipo, ['imagens_por_campo' => ['antes' => 1], 'imagens_a_enviar' => 1,
            'imagens_historico' => [['uuid' => $valida->uuid, 'campo_chave' => 'depois']]])
            ->assertStatus(422)->assertJsonValidationErrors('imagens_por_campo.depois');

        // A janela é parâmetro da empresa: com 120 dias, a foto de 90 dias atrás passa.
        Parametro::create(['empresa_id' => $this->empresa->id, 'chave' => 'FOTOS_REAPROVEITAR_DIAS', 'valor' => '120', 'ativo' => true]);
        $this->registrar($tipo, $base + ['imagens_historico' => [['uuid' => $antiga->uuid, 'campo_chave' => 'antes']]])->assertCreated();
    }

    public function test_galeria_horus_lista_e_serve_as_fotos_anteriores_da_loja(): void
    {
        $anterior = $this->fotoAnterior();
        $this->fotoAnterior(PontoVenda::factory()->create(['empresa_id' => $this->empresa->id]));
        $this->abrirVisita();

        $fotos = $this->getJson("/api/visitas/{$this->visitaUuid}/fotos-anteriores")->assertOk()->assertJsonPath('dias', 60)->json('fotos');
        $this->assertCount(1, $fotos);
        $this->assertSame($anterior->uuid, $fotos[0]['id']);
        $this->assertSame('Foto', $fotos[0]['formulario']);
        $this->get($fotos[0]['url'])->assertOk();

        // Só o dono da visita.
        Sanctum::actingAs(Usuario::factory()->promotor()->create(['empresa_id' => $this->empresa->id]));
        $this->getJson("/api/visitas/{$this->visitaUuid}/fotos-anteriores")->assertForbidden();
    }

    public function test_alertas_de_foto_fora_da_loja_e_fora_de_ordem(): void
    {
        $tipo = $this->criarConquista();
        $this->abrirVisita();
        $registro = $this->registrar($tipo, ['imagens_por_campo' => ['antes' => 1, 'depois' => 1], 'imagens_a_enviar' => 2])->json('registro.id');

        // "Depois" tirado ANTES do "Antes", e o "Antes" a ~11 km da loja.
        $this->enviarFoto($registro, ['idempotency_key' => 'a', 'campo_chave' => 'antes', 'capturada_em' => now()->subMinutes(5)->toIso8601String(), 'latitude' => -3.2, 'longitude' => -60.0]);
        $alertas = $this->enviarFoto($registro, ['idempotency_key' => 'd', 'campo_chave' => 'depois', 'capturada_em' => now()->subMinutes(40)->toIso8601String(), 'latitude' => -3.1, 'longitude' => -60.0])
            ->assertCreated()->json('registro.alertas_fotos');

        $this->assertCount(2, $alertas);
        $this->assertStringContainsString('fora da loja', $alertas[0]);
        $this->assertStringContainsString('fora de ordem', $alertas[1]);
    }

    public function test_registro_sem_foto_nomeada_nao_traz_alerta(): void
    {
        $this->abrirVisita();
        $tipoFoto = TipoRegistro::withoutGlobalScopes()->where('empresa_id', $this->empresa->id)->where('descricao', 'Foto')->value('uuid');
        $registro = $this->registrar($tipoFoto, ['imagens_a_enviar' => 1])->json('registro.id');

        $this->enviarFoto($registro, ['idempotency_key' => 'x'])->assertCreated()->assertJsonPath('registro.alertas_fotos', []);
    }
}

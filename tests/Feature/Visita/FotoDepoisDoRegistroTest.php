<?php

namespace Tests\Feature\Visita;

use App\Models\Empresa;
use App\Models\ImagemRegistro;
use App\Models\PontoVenda;
use App\Models\TipoRegistro;
use App\Models\Usuario;
use App\Models\Visita;
use App\Models\VisitaRegistro;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Registro primeiro, fotos depois (rede lenta): o app manda o registro com `imagens_a_enviar` e
 * cada foto em seguida, uma por vez, em POST /visitas/{v}/registros/{r}/imagens.
 */
class FotoDepoisDoRegistroTest extends TestCase
{
    use RefreshDatabase;

    private Empresa $empresa;

    private Usuario $promotor;

    private string $visitaUuid;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->empresa = Empresa::factory()->create();
        $pdv = PontoVenda::factory()->create(['empresa_id' => $this->empresa->id]);
        $this->promotor = Usuario::factory()->promotor()->create(['empresa_id' => $this->empresa->id]);
        Sanctum::actingAs($this->promotor);
        $this->visitaUuid = $this->postJson('/api/visitas', [
            'ponto_venda_uuid' => $pdv->uuid,
            'latitude' => $pdv->latitude,
            'longitude' => $pdv->longitude,
        ])->json('visita.id');
    }

    // GD não está habilitado neste ambiente — PNG 1x1 real, como em RegistroTest.
    private function imagem(): UploadedFile
    {
        $caminho = tempnam(sys_get_temp_dir(), 'foto').'.png';
        file_put_contents($caminho, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII='));

        return new UploadedFile($caminho, 'foto.png', 'image/png', null, true);
    }

    private function tipoFoto(): string
    {
        return TipoRegistro::withoutGlobalScopes()->where('empresa_id', $this->empresa->id)->where('descricao', 'Foto')->value('uuid');
    }

    private function criarRegistro(array $extra = [])
    {
        return $this->postJson("/api/visitas/{$this->visitaUuid}/registros", ['tipo_registro_uuid' => $this->tipoFoto()] + $extra);
    }

    public function test_foto_a_caminho_conta_pra_exigencia_de_foto(): void
    {
        $this->criarRegistro()->assertStatus(422)->assertJsonValidationErrors('imagens');

        $this->criarRegistro(['imagens_a_enviar' => 2])->assertCreated()->assertJsonCount(0, 'registro.imagens');
    }

    public function test_fotos_chegam_uma_a_uma_na_ordem_e_reenvio_nao_duplica(): void
    {
        $registro = $this->criarRegistro(['imagens_a_enviar' => 2])->json('registro.id');
        $url = "/api/visitas/{$this->visitaUuid}/registros/{$registro}/imagens";

        $this->post($url, ['imagem' => $this->imagem(), 'idempotency_key' => 'r1:0'], ['Accept' => 'application/json'])
            ->assertCreated()->assertJsonCount(1, 'registro.imagens');
        // Resposta perdida, o app manda a mesma foto de novo.
        $this->post($url, ['imagem' => $this->imagem(), 'idempotency_key' => 'r1:0'], ['Accept' => 'application/json'])
            ->assertCreated()->assertJsonCount(1, 'registro.imagens');
        $this->post($url, ['imagem' => $this->imagem(), 'idempotency_key' => 'r1:1'], ['Accept' => 'application/json'])
            ->assertCreated()->assertJsonCount(2, 'registro.imagens');

        $this->assertSame(2, ImagemRegistro::count());
        $ordens = \DB::table('visita_registro_imagem')->orderBy('ordem')->pluck('ordem')->all();
        $this->assertSame([0, 1], $ordens);
    }

    public function test_foto_chega_mesmo_com_a_visita_finalizada_mas_nao_na_cancelada(): void
    {
        $registro = $this->criarRegistro(['imagens_a_enviar' => 1])->json('registro.id');
        $url = "/api/visitas/{$this->visitaUuid}/registros/{$registro}/imagens";
        $visita = Visita::where('uuid', $this->visitaUuid)->firstOrFail();

        $visita->update(['status' => 'FINALIZADA']);
        $this->post($url, ['imagem' => $this->imagem(), 'idempotency_key' => 'a'], ['Accept' => 'application/json'])->assertCreated();

        $visita->update(['status' => 'CANCELADA']);
        $this->post($url, ['imagem' => $this->imagem(), 'idempotency_key' => 'b'], ['Accept' => 'application/json'])->assertStatus(422);
    }

    public function test_registro_de_outra_visita_ou_de_outro_promotor_e_recusado(): void
    {
        $registro = $this->criarRegistro(['imagens_a_enviar' => 1])->json('registro.id');

        $outraVisita = Visita::factory()->create(['empresa_id' => $this->empresa->id, 'usuario_id' => $this->promotor->id]);
        $this->post("/api/visitas/{$outraVisita->uuid}/registros/{$registro}/imagens", ['imagem' => $this->imagem()], ['Accept' => 'application/json'])
            ->assertNotFound();

        Sanctum::actingAs(Usuario::factory()->promotor()->create(['empresa_id' => $this->empresa->id]));
        $this->post("/api/visitas/{$this->visitaUuid}/registros/{$registro}/imagens", ['imagem' => $this->imagem()], ['Accept' => 'application/json'])
            ->assertForbidden();

        $this->assertSame(0, VisitaRegistro::where('uuid', $registro)->firstOrFail()->imagens()->count());
    }
}

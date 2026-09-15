<?php

namespace Tests\Feature\Console;

use App\Enums\PlanoEmpresa;
use App\Enums\UserType;
use App\Models\Empresa;
use App\Models\Parametro;
use App\Models\TipoRegistro;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ProvisionarEmpresaTest extends TestCase
{
    use RefreshDatabase;

    private function argumentos(array $sobrescreve = []): array
    {
        return array_merge([
            '--razao-social' => 'Empresa Provisionada LTDA',
            '--nome-fantasia' => 'Provisionada',
            '--cnpj' => '11222333000199',
            '--plano' => 'PRO',
            '--admin-nome' => 'Admin Provisionado',
            '--admin-email' => 'admin@provisionada.test',
            '--admin-senha' => 'senhaSegura123',
        ], $sobrescreve);
    }

    public function test_cria_empresa_admin_tipos_de_registro_e_parametros(): void
    {
        $this->artisan('empresa:provisionar', $this->argumentos())
            ->assertSuccessful();

        $empresa = Empresa::where('cnpj', '11222333000199')->first();
        $this->assertNotNull($empresa);
        $this->assertSame('Provisionada', $empresa->nome_fantasia);
        $this->assertSame(PlanoEmpresa::PRO, $empresa->plano);

        $admin = Usuario::where('empresa_id', $empresa->id)->first();
        $this->assertNotNull($admin);
        $this->assertSame('admin@provisionada.test', $admin->email);
        $this->assertSame(UserType::ADMIN, $admin->user_type);
        $this->assertTrue(Hash::check('senhaSegura123', $admin->senha_hash));

        $this->assertSame(8, TipoRegistro::where('empresa_id', $empresa->id)->count());
        $this->assertSame(11, Parametro::where('empresa_id', $empresa->id)->count());

        $alertas = TipoRegistro::where('empresa_id', $empresa->id)->where('eh_alerta', true)->pluck('descricao')->sort()->values();
        $this->assertSame(['Avaria', 'Proximo Vencimento'], $alertas->all());
    }

    public function test_senha_gerada_automaticamente_quando_omitida(): void
    {
        $argumentos = $this->argumentos();
        unset($argumentos['--admin-senha']);

        $this->artisan('empresa:provisionar', $argumentos)
            ->expectsOutputToContain('Senha gerada:')
            ->assertSuccessful();

        $admin = Usuario::where('email', 'admin@provisionada.test')->first();
        $this->assertNotNull($admin);
    }

    public function test_cnpj_duplicado_nao_cria_nada(): void
    {
        $this->artisan('empresa:provisionar', $this->argumentos())->assertSuccessful();

        $this->artisan('empresa:provisionar', $this->argumentos([
            '--admin-email' => 'outro-admin@provisionada.test',
        ]))->assertFailed();

        $this->assertSame(1, Empresa::where('cnpj', '11222333000199')->count());
        $this->assertSame(1, Usuario::where('empresa_id', Empresa::where('cnpj', '11222333000199')->value('id'))->count());
    }

    public function test_email_admin_duplicado_falha_a_validacao(): void
    {
        $this->artisan('empresa:provisionar', $this->argumentos())->assertSuccessful();

        $this->artisan('empresa:provisionar', $this->argumentos([
            '--cnpj' => '99888777000166',
        ]))->assertFailed();

        $this->assertSame(1, Empresa::count());
    }

    public function test_plano_invalido_falha_a_validacao(): void
    {
        $this->artisan('empresa:provisionar', $this->argumentos(['--plano' => 'INEXISTENTE']))
            ->assertFailed();

        $this->assertSame(0, Empresa::count());
    }
}

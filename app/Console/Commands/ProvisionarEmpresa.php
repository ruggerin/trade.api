<?php

namespace App\Console\Commands;

use App\Enums\PlanoEmpresa;
use App\Enums\UserType;
use App\Models\Empresa;
use App\Models\Parametro;
use App\Models\TipoRegistro;
use App\Models\Usuario;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Provisiona uma empresa "pronta pra usar" — mesmo fluxo de `EmpresaController::signup`
 * (empresa + primeiro ADMIN + os 3 `TipoRegistro` de fábrica, ver `TipoRegistro::seedPadrao`),
 * mais uma coisa que o signup não faz: grava explicitamente na tabela `Parametro` o valor
 * *default* de cada configuração que o sistema já usa via fallback (ver classes em
 * `App\Support\*` — RaioCheckin, AvisoVencimentoContrato, VisibilidadePontosVenda etc.).
 *
 * Importante: **nenhum desses parâmetros é obrigatório pro sistema funcionar** — toda
 * `App\Support\*Config`-like class já cai num default sensato quando a linha não existe. Rodar
 * este comando não "destrava" nada, só torna esses defaults visíveis e editáveis na tela
 * "Parâmetros" do admin web desde o primeiro dia, em vez de invisíveis atrás de fallback.
 *
 * Idempotente: `--cnpj` já cadastrado não duplica a empresa (erro claro, nada é criado); rodar
 * de novo pra uma empresa que já tem alguns parâmetros só preenche os que faltam
 * (`firstOrCreate` por chave).
 */
class ProvisionarEmpresa extends Command
{
    protected $signature = 'empresa:provisionar
        {--razao-social= : Razão social}
        {--nome-fantasia= : Nome fantasia}
        {--cnpj= : CNPJ (único)}
        {--plano=GRATUITO : GRATUITO, START, PRO ou BUSINESS}
        {--admin-nome= : Nome do primeiro usuário ADMIN}
        {--admin-email= : E-mail de login do ADMIN}
        {--admin-senha= : Senha em texto puro; se omitida, uma senha aleatória é gerada}';

    protected $description = 'Cria uma empresa + primeiro ADMIN + parâmetros default explícitos (tipos de registro de fábrica já nascem sozinhos)';

    /**
     * Default de cada Parametro conhecido pelo sistema hoje — mesmo valor que
     * App\Support\* já usa como fallback quando a linha não existe. Mantido aqui, e não nas
     * classes de Support, de propósito: é só um retrato pra seed, não uma fonte de verdade —
     * a fonte de verdade do default continua em cada Support (ver docblock da classe).
     */
    private const PARAMETROS_DEFAULT = [
        'CHECKIN_RAIO_METROS' => ['valor' => '200', 'descricao' => 'Raio de check-in em metros (App\Support\RaioCheckin)'],
        'CONTRATO_AVISO_DIAS' => ['valor' => '30', 'descricao' => 'Dias de antecedência pra avisar contrato vencendo (App\Support\AvisoVencimentoContrato)'],
        'SYNC_INTERVALO_HORAS' => ['valor' => '4', 'descricao' => 'Intervalo da sincronização silenciosa do app mobile, em horas'],
        'PONTOS_VENDA_RESTRITO_A_VINCULO' => ['valor' => 'false', 'descricao' => 'Modo restrito de visibilidade de PDV (App\Support\VisibilidadePontosVenda) — false = modo aberto'],
        'AGENDA_REQUER_APROVACAO' => ['valor' => 'false', 'descricao' => 'Autonomia do promotor sobre a própria agenda (App\Support\AutonomiaAgenda) — false = autônomo'],
        'REGISTRO_CANCELAMENTO_PERMITIDO' => ['valor' => 'false', 'descricao' => 'Promotor pode cancelar o próprio registro (App\Support\CancelamentoRegistro)'],
        'VISITA_CANCELAMENTO_PERMITIDO' => ['valor' => 'false', 'descricao' => 'Promotor pode cancelar a própria visita em andamento (App\Support\CancelamentoVisita)'],
        'SORTIMENTO_AUTONOMIA_PROMOTOR' => ['valor' => 'AUTONOMO', 'descricao' => 'Autonomia pra vincular produto já existente ao sortimento do PDV (App\Support\AutonomiaSortimento)'],
        'CATALOGO_AUTONOMIA_PROMOTOR' => ['valor' => 'REQUER_APROVACAO', 'descricao' => 'Autonomia pra cadastrar produto novo no catálogo (App\Support\AutonomiaSortimento)'],
        'CODIGO_BARRAS_OBRIGATORIO' => ['valor' => 'false', 'descricao' => 'Exige código de barras ao cadastrar produto (App\Support\CodigoBarrasProduto)'],
        'CODIGO_BARRAS_UNICO' => ['valor' => 'false', 'descricao' => 'Código de barras precisa ser único no catálogo da empresa (App\Support\CodigoBarrasProduto)'],
    ];

    public function handle(): int
    {
        $dados = [
            'razao_social' => $this->option('razao-social') ?: $this->ask('Razão social'),
            'nome_fantasia' => $this->option('nome-fantasia') ?: $this->ask('Nome fantasia'),
            'cnpj' => $this->option('cnpj') ?: $this->ask('CNPJ'),
            'plano' => strtoupper((string) $this->option('plano')),
            'admin_nome' => $this->option('admin-nome') ?: $this->ask('Nome do primeiro ADMIN'),
            'admin_email' => $this->option('admin-email') ?: $this->ask('E-mail do ADMIN'),
        ];
        // Mesmo raciocínio de CriarSuperAdmin: sem símbolo, senha gerada não quebra parser de
        // quem cola direto num JSON de teste.
        $senhaGerada = ! $this->option('admin-senha');
        $dados['admin_senha'] = $this->option('admin-senha') ?: Str::password(20, symbols: false);

        $validador = Validator::make($dados, [
            'razao_social' => ['required', 'string', 'max:255'],
            'nome_fantasia' => ['required', 'string', 'max:255'],
            'cnpj' => ['required', 'string', 'max:20', Rule::unique('empresas', 'cnpj')],
            'plano' => ['required', Rule::in(array_column(PlanoEmpresa::cases(), 'value'))],
            'admin_nome' => ['required', 'string', 'max:255'],
            'admin_email' => ['required', 'email', Rule::unique('usuarios', 'email')],
            'admin_senha' => ['required', 'string', 'min:8'],
        ]);

        if ($validador->fails()) {
            foreach ($validador->errors()->all() as $erro) {
                $this->error($erro);
            }

            return self::FAILURE;
        }

        [$empresa, $admin] = DB::transaction(function () use ($dados) {
            $empresa = Empresa::create([
                'razao_social' => $dados['razao_social'],
                'nome_fantasia' => $dados['nome_fantasia'],
                'cnpj' => $dados['cnpj'],
                'plano' => PlanoEmpresa::from($dados['plano']),
                'limite_usuarios' => 3,
                'limite_pontos_venda' => 3,
            ]);

            $admin = Usuario::create([
                'empresa_id' => $empresa->id,
                'nome' => $dados['admin_nome'],
                'email' => $dados['admin_email'],
                'senha_hash' => Hash::make($dados['admin_senha']),
                'user_type' => UserType::ADMIN,
            ]);

            // Mesmos 3 tipos de fábrica que qualquer empresa nova ganha (signup público,
            // storeSuperadmin, EmpresaFactory) — sem isso o promotor não teria nenhum tipo de
            // registro pra escolher no app até alguém cadastrar um no admin.
            TipoRegistro::seedPadrao($empresa->id);

            foreach (self::PARAMETROS_DEFAULT as $chave => $config) {
                Parametro::firstOrCreate(
                    ['empresa_id' => $empresa->id, 'chave' => $chave],
                    ['valor' => $config['valor'], 'descricao' => $config['descricao'], 'ativo' => true],
                );
            }

            return [$empresa, $admin];
        });

        $this->info("Empresa criada: {$empresa->nome_fantasia} (uuid {$empresa->uuid})");
        $this->info("ADMIN criado: {$admin->email} (uuid {$admin->uuid})");
        $this->info(sprintf('%d parâmetros gravados com o valor default de cada um.', count(self::PARAMETROS_DEFAULT)));

        if ($senhaGerada) {
            $this->warn("Senha gerada: {$dados['admin_senha']}");
            $this->warn('Guarde em local seguro agora — não fica salva em nenhum log.');
        }

        return self::SUCCESS;
    }
}

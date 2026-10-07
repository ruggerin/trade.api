<?php

namespace App\Console\Commands;

use App\Enums\PlanoEmpresa;
use App\Enums\UserType;
use App\Models\Empresa;
use App\Models\TipoRegistro;
use App\Models\Usuario;
use App\Support\ParametrosPadrao;
use App\Support\RelatoriosPadrao;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Provisiona uma empresa "pronta pra usar" — mesmo fluxo de `EmpresaController::signup`
 * (empresa + primeiro ADMIN + os 3 `TipoRegistro` de fábrica, ver `TipoRegistro::seedPadrao`),
 * mais duas coisas que o signup não faz: (1) replica os `TipoRegistro` extras que uma empresa
 * de verdade já usa hoje (Antes/Depois/Avaria/Ponto extra/Vencimento Próximo — ver
 * `TIPOS_REGISTRO_EXTRAS`), e (2) grava explicitamente na tabela `Parametro` o valor *default*
 * de cada configuração que o sistema já usa via fallback (ver classes em `App\Support\*` —
 * RaioCheckin, AvisoVencimentoContrato, VisibilidadePontosVenda etc.).
 *
 * Importante: **nenhum desses parâmetros é obrigatório pro sistema funcionar** — toda
 * `App\Support\*Config`-like class já cai num default sensato quando a linha não existe. Rodar
 * este comando não "destrava" nada, só torna esses defaults visíveis e editáveis na tela
 * "Parâmetros" do admin web desde o primeiro dia, em vez de invisíveis atrás de fallback.
 *
 * Idempotente: `--cnpj` já cadastrado não duplica a empresa (erro claro, nada é criado); rodar
 * de novo pra uma empresa que já tem alguns parâmetros só preenche os que faltam
 * (ver App\Support\ParametrosPadrao::completar).
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

    protected $description = 'Cria uma empresa + primeiro ADMIN + tipos de registro (fábrica + extras) + parâmetros default explícitos';

    /**
     * Além dos 3 "de fábrica" (`TipoRegistro::seedPadrao`), replica os tipos que uma empresa
     * de verdade já usa hoje (ver empresa "Dist Mobile" nos dados de teste) — sem isso, uma
     * empresa provisionada por aqui nasceria mais pobre que uma que já roda há um tempo, dando
     * uma primeira impressão capenga de "cadê os outros tipos".
     *
     * `eh_alerta` só liga em Avaria e Vencimento Próximo — os 2 exemplos que o próprio
     * "Dist Mobile" já usa e que batem com os exemplos canônicos de
     * docs/19-PAINEL-ATIVIDADES.md §2.1 ("Ruptura, Avaria, Vencimento próximo, Ação da
     * concorrência"). Antes/Depois/Ponto extra não pedem ação imediata, ficam de fora do painel
     * de alertas por padrão — a empresa liga na mão se quiser (mesmo switch usado pra Ruptura).
     */
    private const TIPOS_REGISTRO_EXTRAS = [
        ['descricao' => 'Antes', 'icone' => 'image-outline', 'exige_foto' => true, 'permite_vincular_catalogo' => true, 'eh_alerta' => false],
        ['descricao' => 'Depois', 'icone' => 'check-circle-outline', 'exige_foto' => true, 'permite_vincular_catalogo' => true, 'eh_alerta' => false],
        ['descricao' => 'Avaria', 'icone' => 'alert-octagon-outline', 'exige_foto' => true, 'permite_vincular_catalogo' => true, 'eh_alerta' => true],
        ['descricao' => 'Ponto extra', 'icone' => 'star-outline', 'exige_foto' => true, 'permite_vincular_catalogo' => false, 'eh_alerta' => false],
        ['descricao' => 'Proximo Vencimento', 'icone' => 'alert-circle-outline', 'exige_foto' => true, 'permite_vincular_catalogo' => true, 'eh_alerta' => true],
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

            $this->seedTiposRegistroExtras($empresa->id);

            // Catálogo único em App\Support\ParametrosPadrao (o mesmo do botão do superadmin e do
            // comando parametros:completar).
            ParametrosPadrao::completar($empresa);
            // Relatórios padrão do gerador (docs/60 §6.2).
            RelatoriosPadrao::completar($empresa);

            return [$empresa, $admin];
        });

        $this->info("Empresa criada: {$empresa->nome_fantasia} (uuid {$empresa->uuid})");
        $this->info("ADMIN criado: {$admin->email} (uuid {$admin->uuid})");
        $this->info(sprintf('%d parâmetros gravados com o valor default de cada um.', count(ParametrosPadrao::CATALOGO)));

        $this->info(sprintf('%d tipos de registro extras gravados (além dos 3 de fábrica).', count(self::TIPOS_REGISTRO_EXTRAS)));

        if ($senhaGerada) {
            $this->warn("Senha gerada: {$dados['admin_senha']}");
            $this->warn('Guarde em local seguro agora — não fica salva em nenhum log.');
        }

        return self::SUCCESS;
    }

    private function seedTiposRegistroExtras(int $empresaId): void
    {
        foreach (self::TIPOS_REGISTRO_EXTRAS as $indice => $tipo) {
            TipoRegistro::create([
                'empresa_id' => $empresaId,
                'descricao' => $tipo['descricao'],
                'icone' => $tipo['icone'],
                // seedPadrao já usou 0, 1 e 2 (Foto, Ruptura, Observação) — continua a sequência.
                'ordem' => 3 + $indice,
                'exige_foto' => $tipo['exige_foto'],
                'permite_vincular_catalogo' => $tipo['permite_vincular_catalogo'],
                'eh_ruptura' => false,
                'eh_alerta' => $tipo['eh_alerta'],
            ]);
        }
    }
}

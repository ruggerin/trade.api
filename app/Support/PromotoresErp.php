<?php

namespace App\Support;

use App\Enums\UserType;
use App\Models\Empresa;
use App\Models\Supervisor;
use App\Models\Usuario;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

/**
 * Promotores vindos do ERP (docs/96 §7.2, decisão do dono do produto em 26/10/2026): upsert por
 * `codigo_externo` entre os usuários PROMOTOR da empresa.
 *
 * - Promotor novo: nome, e-mail e `senha_inicial` (mín. 8) obrigatórios — o Horus não tem convite
 *   nem "esqueci a senha", então o ERP manda uma senha provisória. Nasce com a licença do app quando
 *   ainda há licença livre no plano (a mesma regra do cadastro pela tela); sem licença livre, entra
 *   sem ela e o resultado avisa.
 * - Promotor existente: atualiza nome, e-mail, ativo e supervisor. A senha NUNCA é trocada por aqui
 *   (o promotor pode já ter mudado a dele); `senha_inicial` só vale na criação.
 * - Código externo de um usuário que não é PROMOTOR (gestor, admin) é erro: o ERP não muda papel.
 * - Supervisor pelo código do ERP (`codigo_supervisor`), cadastrado antes em /supervisores.
 * - Limite de usuários do plano vale pro lote todo.
 */
class PromotoresErp
{
    public const COLUNAS = ['codigo_externo', 'nome', 'email', 'senha_inicial', 'ativo', 'codigo_supervisor'];

    public static function aplicar(Empresa $empresa, array $linhas, bool $simular): array
    {
        $codigos = array_values(array_filter(array_map(fn ($l) => $l['dados']['codigo_externo'] ?? null, $linhas)));
        $existentes = Usuario::withoutGlobalScopes()->where('empresa_id', $empresa->id)->whereIn('codigo_externo', $codigos)->get()->groupBy('codigo_externo');
        $supervisores = Supervisor::withoutGlobalScopes()->where('empresa_id', $empresa->id)->pluck('id', 'codigo_externo');
        $emails = array_values(array_filter(array_map(fn ($l) => isset($l['dados']['email']) ? mb_strtolower($l['dados']['email']) : null, $linhas)));
        $donosEmail = Usuario::withoutGlobalScopes()->whereIn(DB::raw('lower(email)'), $emails)->get(['id', 'email'])->keyBy(fn ($u) => mb_strtolower($u->email));

        $erros = [];
        $operacoes = [];
        $vistos = [];
        $emailsNoLote = [];

        foreach ($linhas as ['numero' => $numero, 'dados' => $dados]) {
            $codigo = $dados['codigo_externo'] ?? null;
            $mensagens = [];
            if (! $codigo) {
                $erros[] = ['linha' => $numero, 'codigo_externo' => null, 'mensagem' => 'codigo_externo é obrigatório: ele é a chave do promotor.'];

                continue;
            }
            if (isset($vistos[$codigo])) {
                $erros[] = ['linha' => $numero, 'codigo_externo' => $codigo, 'mensagem' => "Código repetido no lote (item {$vistos[$codigo]})."];

                continue;
            }
            $vistos[$codigo] = $numero;

            $atuais = $existentes->get($codigo);
            $atual = $atuais?->first();
            if ($atuais && $atuais->count() > 1) {
                $mensagens[] = 'Existe mais de um usuário com este código externo: corrija no cadastro.';
            } elseif ($atual && $atual->user_type !== UserType::PROMOTOR) {
                $mensagens[] = 'Este código externo é de um usuário que não é promotor; o ERP não muda o papel de ninguém.';
            }

            $valores = array_filter($dados, fn ($v) => $v !== null && $v !== '');
            if (isset($valores['email'])) {
                $valores['email'] = mb_strtolower($valores['email']);
            }
            $validador = Validator::make($valores, [
                'nome' => ['string', 'max:255'],
                'email' => ['email', 'max:255'],
                'senha_inicial' => ['string', 'min:8', 'max:255'],
            ], [], ['senha_inicial' => 'senha_inicial']);
            array_push($mensagens, ...$validador->errors()->all());

            if (! $atual) {
                foreach (['nome', 'email', 'senha_inicial'] as $campo) {
                    if (! isset($valores[$campo])) {
                        $mensagens[] = "Promotor novo: {$campo} é obrigatório.";
                    }
                }
            }
            if (isset($valores['email'])) {
                $dono = $donosEmail->get($valores['email']);
                if ($dono && $dono->id !== $atual?->id) {
                    $mensagens[] = "O e-mail {$valores['email']} já é de outro usuário.";
                } elseif (isset($emailsNoLote[$valores['email']])) {
                    $mensagens[] = "E-mail repetido no lote (item {$emailsNoLote[$valores['email']]}).";
                }
                $emailsNoLote[$valores['email']] = $numero;
            }

            $supervisorId = null;
            if (isset($valores['codigo_supervisor'])) {
                $supervisorId = $supervisores->get($valores['codigo_supervisor']);
                if (! $supervisorId) {
                    $mensagens[] = "Supervisor {$valores['codigo_supervisor']} não cadastrado: envie os supervisores antes.";
                }
            }
            $ativo = null;
            if (isset($valores['ativo'])) {
                $ativo = LeitorCsv::booleano($valores['ativo']);
                if ($ativo === null) {
                    $mensagens[] = "Ativo \"{$valores['ativo']}\" inválido: use true ou false.";
                }
            }

            if ($mensagens) {
                $erros[] = ['linha' => $numero, 'codigo_externo' => $codigo, 'mensagem' => implode(' ', $mensagens)];

                continue;
            }

            $operacoes[] = ['atual' => $atual, 'numero' => $numero, 'codigo' => $codigo, 'dados' => array_filter([
                'nome' => $valores['nome'] ?? null,
                'email' => $valores['email'] ?? null,
                'ativo' => $ativo,
                'supervisor_id' => $supervisorId,
            ], fn ($v) => $v !== null), 'senha' => $valores['senha_inicial'] ?? null];
        }

        $novos = count(array_filter($operacoes, fn ($o) => ! $o['atual']));
        if ($empresa->limite_usuarios !== null && $novos > 0) {
            $total = Usuario::withoutGlobalScopes()->where('empresa_id', $empresa->id)->count();
            if ($total + $novos > $empresa->limite_usuarios) {
                $erros[] = ['linha' => 0, 'codigo_externo' => null, 'mensagem' => sprintf(
                    'O lote cria %d promotor(es) novo(s), mas o plano permite %d usuários e já existem %d.',
                    $novos, $empresa->limite_usuarios, $total,
                )];
            }
        }

        $relatorio = ['total' => count($linhas), 'criadas' => $novos, 'atualizadas' => count($operacoes) - $novos, 'erros' => $erros, 'aplicado' => false];
        if ($erros || $simular) {
            return $relatorio;
        }

        $semLicenca = [];
        DB::transaction(function () use ($empresa, $operacoes, &$semLicenca) {
            $livres = $empresa->limite_licencas === null ? PHP_INT_MAX : max(0, $empresa->limite_licencas - UsoLicencas::usadas($empresa));
            foreach ($operacoes as $op) {
                if ($op['atual']) {
                    $op['atual']->update($op['dados']);

                    continue;
                }
                $comLicenca = $livres > 0;
                $livres -= $comLicenca ? 1 : 0;
                if (! $comLicenca) {
                    $semLicenca[] = $op['codigo'];
                }
                Usuario::create([
                    ...$op['dados'],
                    'empresa_id' => $empresa->id,
                    'codigo_externo' => $op['codigo'],
                    'user_type' => UserType::PROMOTOR,
                    'senha_hash' => Hash::make($op['senha']),
                    'licenca_mobile' => $comLicenca,
                ]);
            }
        });

        return ['aplicado' => true] + $relatorio + ($semLicenca ? [
            'sem_licenca' => $semLicenca,
            'aviso' => 'Promotor(es) criado(s) sem licença do app: o plano não tem licença livre. Libere na tela Licenças.',
        ] : []);
    }

    /** @return array<string, true> */
    public static function existentes(Empresa $empresa, array $codigos): array
    {
        return Usuario::withoutGlobalScopes()->where('empresa_id', $empresa->id)->whereIn('codigo_externo', $codigos)
            ->pluck('codigo_externo')->mapWithKeys(fn ($c) => [$c => true])->all();
    }
}

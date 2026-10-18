<?php

namespace App\Support;

use App\Models\Empresa;
use App\Enums\UserType;
use App\Models\Supervisor;
use App\Models\Usuario;
use App\Models\Vendedor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Carga de supervisores e vendedores do ERP (docs/73 Fase 1) — uma regra só pra importação por
 * planilha (ImportacaoDadosController) e pro integrador (EquipeComercialController::sincronizar*).
 *
 * - `codigo_externo` é a chave: existe → atualiza só o que veio preenchido; não existe → cria.
 * - Vendedor aponta pro supervisor pelo `codigo_supervisor` (o `codsupervisor` do Winthor).
 *   Supervisor que ainda não existe NÃO derruba a linha: o vendedor entra sem supervisor, com
 *   aviso, e o vínculo é refeito quando o supervisor chegar (`religarVendedores`).
 * - Planilha é tudo ou nada (com `simular`, igual às outras importações); o integrador grava as
 *   linhas válidas e devolve as recusadas — um e-mail errado não pode travar 500 vendedores.
 * - Sair do ERP é `ativo = false`, nunca exclusão: o histórico continua apontando pra pessoa.
 */
final class EquipeComercial
{
    public const MAX_LINHAS = 5000;

    // `e_mail` = cabeçalho "E-mail", como o Excel costuma vir.
    public const COLUNAS_SUPERVISOR = ['codigo_externo', 'nome', 'telefone', 'email', 'e_mail', 'ativo'];

    public const COLUNAS_VENDEDOR = ['codigo_externo', 'nome', 'telefone', 'email', 'e_mail', 'codsupervisor', 'ativo'];

    /**
     * @param  list<array{numero: int, dados: array<string, mixed>}>  $linhas
     * @return array<string, mixed>
     */
    public static function supervisores(Empresa $empresa, array $linhas, bool $tudoOuNada, bool $simular = false): array
    {
        $existentes = Supervisor::withoutGlobalScopes()->where('empresa_id', $empresa->id)
            ->whereIn('codigo_externo', self::codigos($linhas))->get()->keyBy('codigo_externo');

        [$operacoes, $erros, $avisos] = self::validar($linhas, $existentes, fn () => []);

        return self::aplicar($operacoes, $erros, $avisos, count($linhas), $tudoOuNada, $simular, function (array $operacoes) use ($empresa) {
            foreach ($operacoes as ['atual' => $atual, 'dados' => $dados]) {
                $atual ? $atual->update($dados) : Supervisor::create($dados + ['empresa_id' => $empresa->id]);
            }
            self::religarVendedores($empresa, array_map(fn ($o) => $o['dados']['codigo_externo'], $operacoes));
        });
    }

    /**
     * @param  list<array{numero: int, dados: array<string, mixed>}>  $linhas
     * @return array<string, mixed>
     */
    public static function vendedores(Empresa $empresa, array $linhas, bool $tudoOuNada, bool $simular = false): array
    {
        $existentes = Vendedor::withoutGlobalScopes()->where('empresa_id', $empresa->id)
            ->whereIn('codigo_externo', self::codigos($linhas))->get()->keyBy('codigo_externo');
        $supervisores = Supervisor::withoutGlobalScopes()->where('empresa_id', $empresa->id)->pluck('id', 'codigo_externo');

        [$operacoes, $erros, $avisos] = self::validar($linhas, $existentes, function (array &$dados) use ($supervisores) {
            // `codsupervisor` (planilha, nome do Winthor) e `codigo_supervisor` (integrador) são o mesmo campo.
            $codigo = $dados['codsupervisor'] ?? $dados['codigo_supervisor'] ?? null;
            unset($dados['codsupervisor']);
            if ($codigo === null) {
                return [];
            }
            $dados['codigo_supervisor'] = $codigo;
            $dados['supervisor_id'] = $supervisores[$codigo] ?? null;

            return $dados['supervisor_id'] ? [] : ["Supervisor {$codigo} ainda não cadastrado: o vendedor entra sem supervisor e é vinculado quando ele chegar."];
        });

        return self::aplicar($operacoes, $erros, $avisos, count($linhas), $tudoOuNada, $simular, function (array $operacoes) use ($empresa) {
            foreach ($operacoes as ['atual' => $atual, 'dados' => $dados]) {
                $atual ? $atual->update($dados) : Vendedor::create($dados + ['empresa_id' => $empresa->id]);
            }
        });
    }

    /**
     * Vendedores de uma loja como saem no detalhe dela (docs/73 §11.1). O promotor vê nome, telefone
     * e supervisor (o que ele usa em campo); o e-mail fica só pra gestão.
     *
     * @param  iterable<Vendedor>  $vendedores  com `supervisor` carregado
     * @return list<array<string, mixed>>
     */
    public static function vendedoresDaLoja(iterable $vendedores, ?Usuario $quem): array
    {
        $comEmail = $quem !== null && $quem->user_type !== UserType::PROMOTOR;
        $lista = [];
        foreach ($vendedores as $v) {
            $lista[] = [
                'id' => $v->uuid,
                'codigo_externo' => $v->codigo_externo,
                'nome' => $v->nome,
                'telefone' => $v->telefone,
                'ativo' => $v->ativo,
                'supervisor' => $v->supervisor ? ['id' => $v->supervisor->uuid, 'nome' => $v->supervisor->nome] : null,
            ] + ($comEmail ? ['email' => $v->email] : []);
        }
        usort($lista, fn ($a, $b) => strcmp($a['nome'], $b['nome']));

        return $lista;
    }

    /** Liga ao supervisor os vendedores que chegaram antes dele (mesmo `codigo_supervisor`). */
    public static function religarVendedores(Empresa $empresa, array $codigos): void
    {
        $supervisores = Supervisor::withoutGlobalScopes()->where('empresa_id', $empresa->id)
            ->whereIn('codigo_externo', $codigos)->pluck('id', 'codigo_externo');
        foreach ($supervisores as $codigo => $id) {
            Vendedor::withoutGlobalScopes()->where('empresa_id', $empresa->id)
                ->where('codigo_supervisor', $codigo)
                ->where(fn ($q) => $q->whereNull('supervisor_id')->orWhere('supervisor_id', '!=', $id))
                ->update(['supervisor_id' => $id]);
        }
    }

    /**
     * Validação comum por linha. `$extra` trata o que é próprio da entidade (pode mexer em `$dados`)
     * e devolve avisos — nunca erros: o que ele resolve não impede a linha de entrar.
     *
     * @return array{0: list<array{atual: mixed, dados: array<string, mixed>}>, 1: list<array<string, mixed>>, 2: list<array<string, mixed>>}
     */
    private static function validar(array $linhas, $existentes, callable $extra): array
    {
        $operacoes = [];
        $erros = [];
        $avisos = [];
        $vistos = [];

        foreach ($linhas as ['numero' => $numero, 'dados' => $bruto]) {
            $dados = [];
            foreach ($bruto as $campo => $valor) {
                if (is_string($valor)) {
                    $valor = trim($valor);
                }
                if ($valor !== null && $valor !== '') {
                    $dados[$campo] = is_int($valor) || is_float($valor) ? (string) $valor : $valor;
                }
            }
            if (isset($dados['e_mail'])) {
                $dados['email'] ??= $dados['e_mail'];
                unset($dados['e_mail']);
            }
            $codigo = isset($dados['codigo_externo']) && is_string($dados['codigo_externo']) ? $dados['codigo_externo'] : null;
            $falha = function (string $mensagem) use (&$erros, $numero, $codigo): void {
                $erros[] = ['linha' => $numero, 'codigo_externo' => $codigo, 'mensagem' => $mensagem];
            };

            if ($codigo === null) {
                $falha('Código vazio: ele é a chave da pessoa no ERP.');

                continue;
            }
            if (isset($vistos[$codigo])) {
                $falha("Código repetido no lote (já apareceu na linha {$vistos[$codigo]}).");

                continue;
            }
            $vistos[$codigo] = $numero;
            $atual = $existentes->get($codigo);

            $mensagens = [];
            if (isset($dados['ativo']) && ! is_bool($dados['ativo'])) {
                $ativo = LeitorCsv::booleano((string) $dados['ativo']);
                if ($ativo === null) {
                    $mensagens[] = "Ativo \"{$dados['ativo']}\" inválido: use sim ou não.";
                }
                $dados['ativo'] = $ativo;
            }
            if (! $atual && ! isset($dados['nome'])) {
                $mensagens[] = 'Cadastro novo: o nome é obrigatório.';
            }
            $validador = Validator::make($dados, [
                'codigo_externo' => ['string', 'max:64'],
                'nome' => ['string', 'max:255'],
                'telefone' => ['string', 'max:30'],
                'email' => ['string', 'email', 'max:255'],
                'codsupervisor' => ['string', 'max:64'],
                'codigo_supervisor' => ['string', 'max:64'],
            ], [
                'email.email' => "E-mail \"".(is_string($dados['email'] ?? null) ? $dados['email'] : '')."\" inválido.",
            ]);
            array_push($mensagens, ...$validador->errors()->all());

            if ($mensagens) {
                $falha(implode(' ', $mensagens));

                continue;
            }

            foreach ($extra($dados) as $aviso) {
                $avisos[] = ['linha' => $numero, 'codigo_externo' => $codigo, 'mensagem' => $aviso];
            }
            $dados = array_intersect_key($dados, array_flip([
                'codigo_externo', 'nome', 'telefone', 'email', 'ativo', 'codigo_supervisor', 'supervisor_id',
            ]));
            $operacoes[] = ['atual' => $atual, 'dados' => $dados];
        }

        return [$operacoes, $erros, $avisos];
    }

    /** @return array<string, mixed> */
    private static function aplicar(array $operacoes, array $erros, array $avisos, int $total, bool $tudoOuNada, bool $simular, callable $gravar): array
    {
        $criadas = count(array_filter($operacoes, fn ($o) => ! $o['atual']));
        $atualizadas = count($operacoes) - $criadas;
        $relatorio = fn (bool $aplicado) => [
            'total' => $total, 'criadas' => $criadas, 'atualizadas' => $atualizadas,
            'erros' => $erros, 'avisos' => $avisos, 'aplicado' => $aplicado,
        ];

        if ($simular || ($tudoOuNada && $erros) || ! $operacoes) {
            return $relatorio(false);
        }

        DB::transaction(fn () => $gravar($operacoes));

        return $relatorio(true);
    }

    /** @return list<string> */
    private static function codigos(array $linhas): array
    {
        return array_values(array_filter(array_map(
            fn ($l) => isset($l['dados']['codigo_externo']) ? trim((string) $l['dados']['codigo_externo']) : null,
            $linhas,
        )));
    }
}

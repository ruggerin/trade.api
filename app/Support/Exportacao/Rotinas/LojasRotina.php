<?php

namespace App\Support\Exportacao\Rotinas;

use App\Enums\Permissao;
use App\Http\Controllers\PontoVendaController;
use App\Models\Usuario;
use App\Support\Exportacao\Coluna;
use App\Support\Exportacao\Rotina;

/** Cadastro de lojas — o par do modelo de importação (CNPJ e códigos sempre como texto). */
final class LojasRotina extends Rotina
{
    public static function chave(): string
    {
        return 'lojas';
    }

    public function titulo(): string
    {
        return 'Lojas';
    }

    public function permissao(): Permissao
    {
        return Permissao::TELA_LOJAS;
    }

    public function colunas(): array
    {
        return [
            new Coluna('codigo_externo', 'Código (ERP)'),
            new Coluna('cnpj', 'CNPJ'),
            new Coluna('razao_social', 'Razão social'),
            new Coluna('fantasia', 'Nome fantasia'),
            new Coluna('rede', 'Rede'),
            new Coluna('ramo', 'Ramo de atividade'),
            new Coluna('endereco', 'Endereço'),
            new Coluna('numero', 'Número'),
            new Coluna('bairro', 'Bairro'),
            new Coluna('cidade', 'Cidade'),
            new Coluna('uf', 'UF'),
            new Coluna('cep', 'CEP'),
            new Coluna('telefone', 'Telefone'),
            new Coluna('email', 'E-mail'),
            new Coluna('promotores', 'Promotores'),
            new Coluna('ativo', 'Ativa', 'booleano'),
            new Coluna('atualizado_em', 'Atualizado em', 'data_hora'),
        ];
    }

    public function filtrosPermitidos(): array
    {
        return [
            'ativo', 'busca', 'rede_loja_uuid', 'cidade', 'uf', 'sem_promotor', 'data_inicio', 'data_fim', 'data_campo',
            'razao_social', 'fantasia', 'cnpj', 'promotor_uuid', 'com_contrato_ativo', 'ordenar',
        ];
    }

    public function linhas(array $filtros, Usuario $usuario): iterable
    {
        foreach ($this->paginar(PontoVendaController::class, 'index', 'pontos_venda', $filtros, $usuario) as $p) {
            yield [
                'codigo_externo' => $p['codigo_externo'] ?? null,
                'cnpj' => $p['cnpj'] ?? null,
                'razao_social' => $p['razao_social'] ?? null,
                'fantasia' => $p['fantasia'] ?? null,
                'rede' => self::pegar($p, 'rede_loja.descricao'),
                'ramo' => self::pegar($p, 'ramo_atividade.descricao'),
                'endereco' => $p['endereco'] ?? null,
                'numero' => $p['numero'] ?? null,
                'bairro' => $p['bairro'] ?? null,
                'cidade' => $p['cidade'] ?? null,
                'uf' => $p['uf'] ?? null,
                'cep' => $p['cep'] ?? null,
                'telefone' => $p['telefone'] ?? null,
                'email' => $p['email'] ?? null,
                'promotores' => collect($p['promotores'] ?? [])->pluck('nome')->filter()->implode(', ') ?: null,
                'ativo' => (bool) ($p['ativo'] ?? false),
                'atualizado_em' => $p['updated_at'] ?? null,
            ];
        }
    }
}

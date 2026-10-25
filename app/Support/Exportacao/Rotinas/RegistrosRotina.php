<?php

namespace App\Support\Exportacao\Rotinas;

use App\Enums\Permissao;
use App\Http\Controllers\RegistroController;
use App\Models\Usuario;
use App\Support\Exportacao\Coluna;
use App\Support\Exportacao\Rotina;

/** Tela Registros (docs/44, 56): ocorrências em campo, com status e motivo do alerta. */
final class RegistrosRotina extends Rotina
{
    public static function chave(): string
    {
        return 'registros';
    }

    public function titulo(): string
    {
        return 'Registros';
    }

    public function permissao(): Permissao
    {
        return Permissao::TELA_REGISTROS;
    }

    public function colunas(): array
    {
        return [
            new Coluna('ocorrido_em', 'Data/Hora', 'data_hora'),
            new Coluna('tipo', 'Tipo'),
            new Coluna('codigo_cliente', 'Código do cliente'),
            new Coluna('loja', 'Loja'),
            new Coluna('rede', 'Rede'),
            new Coluna('produto', 'Produto'),
            new Coluna('observacao', 'Observação'),
            new Coluna('promotor', 'Promotor'),
            new Coluna('vendedor', 'Vendedor'),
            new Coluna('status', 'Status'),
            new Coluna('motivo', 'Motivo da resolução'),
        ];
    }

    public function filtrosPermitidos(): array
    {
        return ['tipo_registro_uuid', 'ponto_venda_uuid', 'rede_loja_uuid', 'data_inicio', 'data_fim', 'alerta_status', 'produto_auditoria_uuid', 'ruptura', 'codigo_externo', 'vendedor_uuid'];
    }

    public function linhas(array $filtros, Usuario $usuario): iterable
    {
        foreach ($this->paginar(RegistroController::class, 'index', 'registros', $filtros, $usuario) as $r) {
            yield [
                'ocorrido_em' => $r['ocorrido_em'] ?? null,
                'tipo' => self::pegar($r, 'tipo_registro.descricao'),
                'codigo_cliente' => self::pegar($r, 'ponto_venda.codigo_externo'),
                'loja' => self::pegar($r, 'ponto_venda.fantasia'),
                'rede' => self::pegar($r, 'ponto_venda.rede_loja.descricao'),
                'produto' => self::pegar($r, 'produto_auditoria.descricao'),
                'observacao' => $r['observacao'] ?? null,
                'promotor' => self::pegar($r, 'usuario.nome'),
                'vendedor' => collect($r['ponto_venda']['vendedores'] ?? [])->pluck('nome')->implode(', ') ?: null,
                'status' => match ($r['status'] ?? null) {
                    'aberto' => 'Aberto',
                    'resolvido' => 'Resolvido',
                    default => null,
                },
                'motivo' => ($r['status'] ?? null) === 'resolvido'
                    ? (self::pegar($r, 'motivo.descricao') ?? ($r['motivo_texto'] ?? null))
                    : null,
            ];
        }
    }
}

<?php

namespace App\Support\Exportacao\Rotinas;

use App\Enums\Permissao;
use App\Http\Controllers\VisitaController;
use App\Models\Usuario;
use App\Support\Exportacao\Coluna;
use App\Support\Exportacao\Rotina;
use Carbon\Carbon;

/** Tela Visitas: check-in e checkout, duração, distância, afastamento e quantidade de registros. */
final class VisitasRotina extends Rotina
{
    public static function chave(): string
    {
        return 'visitas';
    }

    public function titulo(): string
    {
        return 'Visitas';
    }

    public function permissao(): Permissao
    {
        return Permissao::TELA_VISITAS;
    }

    public function colunas(): array
    {
        return [
            new Coluna('loja', 'Loja'),
            new Coluna('endereco', 'Endereço'),
            new Coluna('promotor', 'Promotor'),
            new Coluna('status', 'Status'),
            new Coluna('inicio', 'Início', 'data_hora'),
            new Coluna('fim', 'Fim', 'data_hora'),
            new Coluna('duracao', 'Duração (min)', 'minutos'),
            new Coluna('distancia_checkin', 'Distância no check-in (m)', 'inteiro'),
            new Coluna('distancia_checkout', 'Distância no checkout (m)', 'inteiro'),
            new Coluna('checkout_tipo', 'Tipo de checkout'),
            new Coluna('afastamento', 'Fora da loja (min)', 'minutos'),
            new Coluna('registros', 'Registros', 'inteiro'),
            new Coluna('campanha', 'Campanha'),
        ];
    }

    public function filtrosPermitidos(): array
    {
        return ['usuario_uuid', 'ponto_venda_uuid', 'data_inicio', 'data_fim', 'status', 'produto_auditoria_uuid', 'ruptura', 'afastamento'];
    }

    public function linhas(array $filtros, Usuario $usuario): iterable
    {
        foreach ($this->paginar(VisitaController::class, 'index', 'visitas', $filtros, $usuario) as $v) {
            $inicio = $v['inicio_data'] ?? null;
            $fim = $v['fim_data'] ?? null;

            yield [
                'loja' => self::pegar($v, 'ponto_venda.fantasia'),
                'endereco' => self::pegar($v, 'ponto_venda.endereco'),
                'promotor' => self::pegar($v, 'usuario.nome'),
                'status' => $v['status'] ?? null,
                'inicio' => $inicio,
                'fim' => $fim,
                // Duração bruta (check-in → checkout); o "tempo na loja" descontado é o relatório R2.
                'duracao' => $inicio && $fim ? max(0, (int) round(Carbon::parse($inicio)->diffInSeconds(Carbon::parse($fim)) / 60)) : null,
                'distancia_checkin' => $v['inicio_distancia_metros'] ?? null,
                'distancia_checkout' => $v['fim_distancia_metros'] ?? null,
                'checkout_tipo' => $v['checkout_tipo'] ?? null,
                'afastamento' => self::pegar($v, 'afastamento.minutos'),
                'registros' => $v['registros_count'] ?? null,
                'campanha' => self::pegar($v, 'campanha.descricao'),
            ];
        }
    }
}

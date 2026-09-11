<?php

namespace App\Support;

use App\Models\Empresa;
use App\Models\Parametro;

/**
 * Duas configurações independentes por empresa sobre o código de barras de um produto do
 * catálogo — se é obrigatório informar no cadastro (admin web ou self-service do promotor pela
 * visita, mesmo parâmetro pros dois) e se precisa ser único. Mesmo padrão de leitura de
 * `Parametro` já usado por `AutonomiaAgenda`/`RaioCheckin` — ausente/inativo = default (os dois
 * default `false`, formato antigo de cadastro sem código de barras continua funcionando).
 */
class CodigoBarrasProduto
{
    private const VALORES_VERDADEIROS = ['1', 'true', 'sim', 'yes'];

    public static function obrigatorio(Empresa $empresa): bool
    {
        return self::flagAtiva($empresa, 'CODIGO_BARRAS_OBRIGATORIO');
    }

    public static function unico(Empresa $empresa): bool
    {
        return self::flagAtiva($empresa, 'CODIGO_BARRAS_UNICO');
    }

    private static function flagAtiva(Empresa $empresa, string $chave): bool
    {
        $parametro = Parametro::query()
            ->where('empresa_id', $empresa->id)
            ->where('chave', $chave)
            ->where('ativo', true)
            ->first();

        if (! $parametro) {
            return false;
        }

        return in_array(strtolower((string) $parametro->valor), self::VALORES_VERDADEIROS, true);
    }
}

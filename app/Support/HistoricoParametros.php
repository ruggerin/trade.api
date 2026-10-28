<?php

namespace App\Support;

use App\Enums\UserType;
use App\Models\Parametro;
use App\Models\ParametroHistorico;
use App\Models\Usuario;

/**
 * Auditoria das configurações (parâmetros) da empresa. Os eventos do model Parametro chamam
 * `registrar`, então tela, Avançado, suporte e comandos ficam todos registrados, na mesma transação
 * da alteração.
 *
 * Origem: quem chama pode dizer de onde veio (`comOrigem`); sem isso, deduz — comando de console,
 * SUPERADMIN (o suporte, que não é da empresa) ou "sistema".
 */
class HistoricoParametros
{
    public const CONFIGURACOES = 'CONFIGURACOES';

    public const AVANCADO = 'AVANCADO';

    public const PADROES = 'PADROES';

    public const SUPORTE = 'SUPORTE';

    public const COMANDO = 'COMANDO';

    public const SISTEMA = 'SISTEMA';

    private static ?string $origem = null;

    /**
     * Roda `$fazer` marcando as alterações com a origem dada.
     *
     * @template T
     *
     * @param  callable(): T  $fazer
     * @return T
     */
    public static function comOrigem(string $origem, callable $fazer): mixed
    {
        $anterior = self::$origem;
        self::$origem = $origem;
        try {
            return $fazer();
        } finally {
            self::$origem = $anterior;
        }
    }

    public static function registrar(Parametro $parametro, string $evento): void
    {
        $criado = $evento === 'created';
        $apagado = $evento === 'deleted';
        if (! $criado && ! $apagado && ! $parametro->wasChanged(['valor', 'ativo'])) {
            return;
        }

        $usuario = auth()->user();
        $usuario = $usuario instanceof Usuario ? $usuario : null;

        ParametroHistorico::withoutGlobalScopes()->create([
            'empresa_id' => $parametro->empresa_id,
            'chave' => $parametro->chave,
            'valor_anterior' => $criado ? null : (string) $parametro->getOriginal('valor'),
            'valor_novo' => $apagado ? null : $parametro->valor,
            'ativo_anterior' => $criado ? null : (bool) $parametro->getOriginal('ativo'),
            'ativo_novo' => $apagado ? null : (bool) $parametro->ativo,
            'usuario_id' => $usuario?->id,
            'usuario_nome' => $usuario?->nome,
            'origem' => self::origem($usuario),
        ]);
    }

    private static function origem(?Usuario $usuario): string
    {
        if ($usuario?->user_type === UserType::SUPERADMIN) {
            return self::SUPORTE;
        }
        if (self::$origem !== null) {
            return self::$origem;
        }

        return app()->runningInConsole() && ! app()->runningUnitTests() ? self::COMANDO : self::SISTEMA;
    }
}

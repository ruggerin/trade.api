<?php

namespace App\Support;

use App\Models\Empresa;
use App\Models\PontoVenda;
use App\Models\Usuario;

/**
 * Uso × limite do plano de uma empresa — fonte única pra tela do SUPERADMIN
 * (EmpresaController::showSuperadmin), a do ADMIN (EmpresaController::licencas) e as travas de
 * criar/reativar usuário (UsuarioController). Ver docs/67-LICENCIAMENTO-E-SESSAO-UNICA.md §3.
 *
 * Licença = usuário ativo com a licença do app mobile atribuída, de qualquer user_type (docs/67
 * §3, decisão de 12/10/2026 — antes era "PROMOTOR ativo").
 * withoutGlobalScopes() explícito: pro SUPERADMIN o scope de tenant não filtra nada.
 */
class UsoLicencas
{
    public static function usadas(Empresa $empresa): int
    {
        return Usuario::withoutGlobalScopes()
            ->where('empresa_id', $empresa->id)
            ->where('licenca_mobile', true)
            ->where('ativo', true)
            ->count();
    }

    /** true quando a empresa ainda comporta mais um PROMOTOR ativo (limite null = ilimitado). */
    public static function comportaMaisUma(Empresa $empresa): bool
    {
        return $empresa->limite_licencas === null || self::usadas($empresa) < $empresa->limite_licencas;
    }

    public static function mensagemLimite(Empresa $empresa): string
    {
        return "Limite de licenças do app mobile atingido ({$empresa->limite_licencas}). Fale com o suporte pra contratar mais.";
    }

    /**
     * @return array{licencas_usadas: int, licencas_limite: ?int, licencas_disponiveis: ?int, usuarios_total: int, usuarios_limite: ?int, pontos_venda_total: int, pontos_venda_limite: ?int}
     */
    public static function resumo(Empresa $empresa): array
    {
        $usadas = self::usadas($empresa);

        return [
            'licencas_usadas' => $usadas,
            'licencas_limite' => $empresa->limite_licencas,
            'licencas_disponiveis' => $empresa->limite_licencas === null ? null : max(0, $empresa->limite_licencas - $usadas),
            'usuarios_total' => Usuario::withoutGlobalScopes()->where('empresa_id', $empresa->id)->count(),
            'usuarios_limite' => $empresa->limite_usuarios,
            'pontos_venda_total' => PontoVenda::withoutGlobalScopes()
                ->where('empresa_id', $empresa->id)
                ->where('ativo', true)
                ->count(),
            'pontos_venda_limite' => $empresa->limite_pontos_venda,
        ];
    }
}

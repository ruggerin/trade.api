<?php

namespace App\Enums;

/**
 * O que um token de integração pode acessar (docs/96 §5), no formato `recurso:acao`. Nenhum token
 * nasce com tudo: o ADMIN marca cada escopo na tela, e cada rota de /api/integracao/v1 exige o
 * seu (middleware `escopo:visitas:ler`, App\Http\Middleware\EnsureEscopoIntegracao).
 *
 * Escrita nunca implica leitura: `lojas:escrever` grava lojas mas não as lê de volta.
 * `agenda:escrever` está no catálogo pra tela mostrar "em breve"; `disponivel()` barra na
 * validação até existir a rota.
 */
enum EscopoIntegracao: string
{
    case VISITAS_LER = 'visitas:ler';
    case REGISTROS_LER = 'registros:ler';
    case RUPTURAS_LER = 'rupturas:ler';
    case XP_LER = 'xp:ler';
    case ORDENS_SERVICO_LER = 'ordens_servico:ler';
    case LOJAS_LER = 'lojas:ler';
    case LOJAS_ESCREVER = 'lojas:escrever';
    case PRODUTOS_LER = 'produtos:ler';
    case PRODUTOS_ESCREVER = 'produtos:escrever';
    case MIX_LER = 'mix:ler';
    case MIX_ESCREVER = 'mix:escrever';
    case VENDEDORES_LER = 'vendedores:ler';
    case VENDEDORES_ESCREVER = 'vendedores:escrever';
    case USUARIOS_LER = 'usuarios:ler';
    case USUARIOS_ESCREVER = 'usuarios:escrever';
    case PEDIDOS_LER = 'pedidos:ler';
    case PEDIDOS_ESCREVER = 'pedidos:escrever';
    case AGENDA_ESCREVER = 'agenda:escrever';

    public function disponivel(): bool
    {
        return $this !== self::AGENDA_ESCREVER;
    }

    public function recurso(): string
    {
        return explode(':', $this->value)[0];
    }

    public function acao(): string
    {
        return explode(':', $this->value)[1];
    }

    public function rotulo(): string
    {
        return match ($this->recurso()) {
            'visitas' => 'Visitas',
            'registros' => 'Registros e fotos',
            'rupturas' => 'Rupturas',
            'xp' => 'XP',
            'ordens_servico' => 'Ordens de serviço',
            'lojas' => 'Lojas (PDVs)',
            'produtos' => 'Produtos',
            'mix' => 'Mix da loja',
            'vendedores' => 'Vendedores e supervisores',
            'usuarios' => 'Promotores',
            'pedidos' => 'Pedidos do ERP',
            'agenda' => 'Agenda de visitas',
        };
    }

    public function descricao(): ?string
    {
        return match ($this->recurso()) {
            'visitas' => 'horários, check-in/out, afastamento',
            'registros' => 'fotos por link temporário',
            'xp' => 'régua por promotor e por visita',
            'ordens_servico' => 'agenda prevista × realizada',
            'lojas' => 'escrever inclui redes e ramos de atividade',
            'produtos' => 'escrever inclui marcas, departamentos e seções',
            'mix' => 'produtos de cada loja (sortimento)',
            'vendedores' => 'cadastro do ERP e carteira vendedor × loja',
            'usuarios' => 'ler: nome e código, nunca CPF, e-mail ou telefone; escrever: cadastro com senha inicial',
            'pedidos' => 'pedidos, faturamento e entregas',
            default => null,
        };
    }

    /** @return list<string> */
    public static function valoresDisponiveis(): array
    {
        return array_values(array_map(
            fn (self $e) => $e->value,
            array_filter(self::cases(), fn (self $e) => $e->disponivel()),
        ));
    }
}

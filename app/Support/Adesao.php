<?php

namespace App\Support;

use App\Enums\UserType;
use App\Models\Empresa;
use App\Models\Usuario;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Adesão/retenção — docs/52-LOG-DE-ACESSO-E-ADESAO.md. Base: `acessos_diarios` (um registro por
 * usuário × dia × app), que mede USO e não login (o token não expira). Concentra as contas dos
 * dois recortes: por usuário (ADMIN/GESTOR vendo o próprio time) e por empresa (SUPERADMIN vendo
 * a carteira).
 */
final class Adesao
{
    public const APP_MOBILE = 'MOBILE';

    public const APP_ADMIN = 'ADMIN';

    /** Janelas da carteira do SUPERADMIN (docs/52 §6 decisão 2). */
    public const JANELAS = [7, 30, 90];

    /** "Sumiu" (docs/52 §6 decisão 1): promotor deveria usar todo dia útil; admin/gestor, toda semana. */
    public const SUMIDO_DIAS_UTEIS_PROMOTOR = 3;

    public const SUMIDO_DIAS_CORRIDOS_GESTAO = 7;

    /** De quanto em quanto tempo o horário do último acesso é regravado (evita escrita por requisição). */
    public const INTERVALO_ULTIMO_EM_MINUTOS = 5;

    /** Frequência do usuário: em quantos dias dos últimos 30 ele usou o sistema. */
    public const JANELA_FREQUENCIA = 30;

    /**
     * Qual app fez a requisição: o header `X-Client` (os dois clientes mandam); sem ele (APK antigo),
     * pelo papel — na prática só PROMOTOR usa o app.
     */
    public static function appDaRequisicao(Request $request, Usuario $usuario): string
    {
        return match (strtolower((string) $request->header('X-Client'))) {
            'mobile' => self::APP_MOBILE,
            'admin' => self::APP_ADMIN,
            default => $usuario->user_type === UserType::PROMOTOR ? self::APP_MOBILE : self::APP_ADMIN,
        };
    }

    /**
     * Marca que o usuário usou o sistema hoje (no fuso da empresa) nesse app. Idempotente: a
     * primeira requisição do dia cria a linha; as seguintes só avançam `ultimo_em`, e no máximo a
     * cada INTERVALO_ULTIMO_EM_MINUTOS (uma query só, como antes).
     */
    public static function registrar(Usuario $usuario, string $app): void
    {
        $agora = now();
        DB::statement(
            'INSERT INTO acessos_diarios (usuario_id, empresa_id, user_type, app, data, created_at, ultimo_em)
             VALUES (?, ?, ?, ?, ?, ?, ?)
             ON CONFLICT (usuario_id, data, app) DO UPDATE SET ultimo_em = EXCLUDED.ultimo_em
             WHERE acessos_diarios.ultimo_em IS NULL OR acessos_diarios.ultimo_em < ?',
            [
                $usuario->id,
                $usuario->empresa_id,
                $usuario->user_type->value,
                $app,
                Fuso::hoje(Fuso::daEmpresa($usuario->empresa))->toDateString(),
                $agora,
                $agora,
                $agora->copy()->subMinutes(self::INTERVALO_ULTIMO_EM_MINUTOS),
            ],
        );
    }

    /**
     * Resumo de uso de cada usuário, numa consulta só pra lista inteira — vai em
     * `$usuario->acessoResumo` (lido pelo UsuarioResource).
     *
     * @param  iterable<Usuario>  $usuarios
     */
    public static function anexar(iterable $usuarios): void
    {
        $porId = collect($usuarios)->keyBy('id');
        if ($porId->isEmpty()) {
            return;
        }

        $ultimos = DB::table('acessos_diarios')
            ->whereIn('usuario_id', $porId->keys())
            ->groupBy('usuario_id', 'app')
            ->get(['usuario_id', 'app', DB::raw('MAX(data) as ultima'), DB::raw('MAX(ultimo_em) as ultimo_horario')])
            ->groupBy('usuario_id');

        $frequencia = DB::table('acessos_diarios')
            ->whereIn('usuario_id', $porId->keys())
            ->where('data', '>', now()->subDays(self::JANELA_FREQUENCIA + 1)->toDateString())
            ->groupBy('usuario_id')
            ->selectRaw('usuario_id, COUNT(DISTINCT data) as dias')
            ->pluck('dias', 'usuario_id');

        foreach ($porId as $id => $usuario) {
            $porApp = collect($ultimos->get($id, []))->pluck('ultima', 'app');
            $horarioPorApp = collect($ultimos->get($id, []))->pluck('ultimo_horario', 'app')->filter();
            $horario = fn (?string $valor) => $valor ? Carbon::parse($valor, 'UTC')->toIso8601String() : null;
            $ultimo = $porApp->max();
            $hoje = Fuso::hoje(Fuso::daEmpresa($usuario->empresa));
            $ultimoDia = $ultimo ? Carbon::parse($ultimo, $hoje->tzName)->startOfDay() : null;

            $usuario->acessoResumo = [
                'ultimo_em' => $ultimo,
                'mobile_em' => $porApp->get(self::APP_MOBILE),
                'admin_em' => $porApp->get(self::APP_ADMIN),
                // Data e hora (UTC, ISO) do último acesso — a lista de Usuários mostra isso.
                'ultimo_horario' => $horario($horarioPorApp->max()),
                'mobile_horario' => $horario($horarioPorApp->get(self::APP_MOBILE)),
                'admin_horario' => $horario($horarioPorApp->get(self::APP_ADMIN)),
                'dias_ativos_30d' => (int) ($frequencia[$id] ?? 0),
                'dias_sem_acesso' => $ultimoDia ? (int) $ultimoDia->diffInDays($hoje) : null,
                'sumido' => self::sumido($usuario->user_type, $ultimoDia, $hoje),
            ];
        }
    }

    /**
     * Passou do limite sem usar? Sem nenhum registro = não afirma nada (pode ser usuário recém
     * criado) — a tela mostra "sem acesso registrado".
     */
    public static function sumido(UserType $tipo, ?Carbon $ultimoDia, Carbon $hoje): bool
    {
        if ($ultimoDia === null) {
            return false;
        }

        if ($tipo === UserType::PROMOTOR) {
            // Dias úteis inteiros perdidos: depois do último acesso e antes de hoje (hoje ainda não acabou).
            $perdidos = 0;
            for ($dia = $ultimoDia->copy()->addDay(); $dia->lt($hoje); $dia->addDay()) {
                if (! $dia->isWeekend()) {
                    $perdidos++;
                }
            }

            return $perdidos >= self::SUMIDO_DIAS_UTEIS_PROMOTOR;
        }

        return $ultimoDia->diffInDays($hoje) - 1 >= self::SUMIDO_DIAS_CORRIDOS_GESTAO;
    }

    /**
     * Adesão por empresa pra carteira do SUPERADMIN: última atividade e quantos usuários usaram
     * em cada janela, no total e por app.
     *
     * @param  list<int>  $empresaIds
     * @return array<int, array<string, mixed>> empresa_id => resumo
     */
    public static function porEmpresa(array $empresaIds): array
    {
        if ($empresaIds === []) {
            return [];
        }

        $hoje = now()->utc()->startOfDay();
        $selects = ['empresa_id', DB::raw('MAX(data) as ultima')];
        foreach (self::JANELAS as $dias) {
            $desde = $hoje->copy()->subDays($dias - 1)->toDateString();
            $selects[] = DB::raw("COUNT(DISTINCT usuario_id) FILTER (WHERE data >= '{$desde}') as ativos_{$dias}");
            $selects[] = DB::raw("COUNT(DISTINCT usuario_id) FILTER (WHERE data >= '{$desde}' AND app = 'MOBILE') as mobile_{$dias}");
            $selects[] = DB::raw("COUNT(DISTINCT usuario_id) FILTER (WHERE data >= '{$desde}' AND app = 'ADMIN') as admin_{$dias}");
        }

        $linhas = DB::table('acessos_diarios')
            ->whereIn('empresa_id', $empresaIds)
            ->groupBy('empresa_id')
            ->get($selects)
            ->keyBy('empresa_id');

        $usuariosAtivos = Usuario::withoutGlobalScopes()
            ->whereIn('empresa_id', $empresaIds)
            ->where('ativo', true)
            ->groupBy('empresa_id')
            ->selectRaw('empresa_id, COUNT(*) as total')
            ->pluck('total', 'empresa_id');

        $resultado = [];
        foreach ($empresaIds as $id) {
            $linha = $linhas->get($id);
            $janelas = [];
            // Lista com `dias` explícito (chave numérica viraria lista renumerada no JSON).
            foreach (self::JANELAS as $dias) {
                $janelas[] = [
                    'dias' => $dias,
                    'usuarios' => (int) ($linha->{"ativos_{$dias}"} ?? 0),
                    'mobile' => (int) ($linha->{"mobile_{$dias}"} ?? 0),
                    'admin' => (int) ($linha->{"admin_{$dias}"} ?? 0),
                ];
            }
            $resultado[$id] = [
                'ultima_atividade' => $linha->ultima ?? null,
                'usuarios_ativos' => (int) ($usuariosAtivos[$id] ?? 0),
                'janelas' => $janelas,
            ];
        }

        return $resultado;
    }

    /** @param  iterable<Empresa>  $empresas */
    public static function anexarEmpresas(iterable $empresas): void
    {
        $lista = collect($empresas);
        $resumos = self::porEmpresa($lista->pluck('id')->all());
        foreach ($lista as $empresa) {
            $empresa->adesao = $resumos[$empresa->id] ?? null;
        }
    }
}

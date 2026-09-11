<?php

namespace App\Console\Commands;

use App\Enums\UserType;
use App\Models\Usuario;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * SUPERADMIN é equipe interna (backoffice), sem empresa_id — não existe endpoint público pra
 * criar esse user_type, por segurança. Provisionamento é sempre manual, via este comando.
 */
class CriarSuperAdmin extends Command
{
    protected $signature = 'usuario:criar-superadmin
        {--nome= : Nome do usuário}
        {--email= : E-mail de login}
        {--senha= : Senha em texto puro; se omitida, uma senha aleatória é gerada}';

    protected $description = 'Cria um usuário SUPERADMIN (equipe interna, sem empresa)';

    public function handle(): int
    {
        $nome = $this->option('nome') ?: $this->ask('Nome');
        $email = $this->option('email') ?: $this->ask('E-mail');
        // Sem símbolos: uma senha gerada com `\`/`"` quebra o parse de quem cola direto num
        // corpo JSON de teste (Insomnia, curl) sem escapar — já aconteceu. Letras+números em
        // 20 posições ainda dá entropia de sobra.
        $senha = $this->option('senha') ?: Str::password(20, symbols: false);

        $validator = Validator::make(
            compact('nome', 'email', 'senha'),
            [
                'nome' => ['required', 'string', 'max:255'],
                'email' => ['required', 'email', 'unique:usuarios,email'],
                'senha' => ['required', 'string', 'min:8'],
            ]
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $erro) {
                $this->error($erro);
            }

            return self::FAILURE;
        }

        $usuario = Usuario::create([
            'empresa_id' => null,
            'nome' => $nome,
            'email' => $email,
            'senha_hash' => Hash::make($senha),
            'user_type' => UserType::SUPERADMIN,
        ]);

        $this->info("SUPERADMIN criado: {$usuario->email} (uuid {$usuario->uuid})");

        if (! $this->option('senha')) {
            $this->warn("Senha gerada: {$senha}");
            $this->warn('Guarde em local seguro agora — não fica salva em nenhum log.');
        }

        return self::SUCCESS;
    }
}

<?php

namespace App\Console\Commands;

use App\Support\Acompanhamentos;
use Illuminate\Console\Command;

/** "Ainda está lá?" com prazo vencido vira EXPIRADO (docs/83 §5.2). */
class ExpirarAcompanhamentos extends Command
{
    protected $signature = 'acompanhamentos:expirar';

    protected $description = 'Marca como EXPIRADO o acompanhamento de registro que passou do prazo máximo';

    public function handle(): int
    {
        $this->info(Acompanhamentos::expirarVencidos().' acompanhamento(s) expirado(s).');

        return self::SUCCESS;
    }
}

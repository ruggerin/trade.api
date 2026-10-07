<?php

namespace App\Console\Commands;

use App\Models\Empresa;
use App\Support\RelatoriosPadrao;
use Illuminate\Console\Command;

/**
 * Distribui os relatórios padrão (App\Support\RelatoriosPadrao, docs/60 §6.2): cria os que faltam
 * e atualiza os de versão antiga. Nunca toca em relatório do cliente. Mesmo efeito do botão
 * "Completar relatórios padrão" no detalhe da empresa do superadmin, só que em lote — rodar no
 * deploy pra preencher as empresas existentes.
 *
 *   php artisan relatorios:completar                 # todas as empresas ativas
 *   php artisan relatorios:completar --empresa=UUID  # uma empresa só
 *   php artisan relatorios:completar --simular       # só mostra o que faria
 */
class CompletarRelatorios extends Command
{
    protected $signature = 'relatorios:completar
        {--empresa= : UUID de uma empresa específica (padrão: todas as ativas)}
        {--simular : Só lista o que seria criado/atualizado, sem gravar nada}';

    protected $description = 'Cria/atualiza nas empresas os relatórios padrão do gerador de relatórios';

    public function handle(): int
    {
        $empresas = Empresa::query()
            ->when($this->option('empresa'), fn ($q, $uuid) => $q->where('uuid', $uuid), fn ($q) => $q->where('ativo', true))
            ->orderBy('nome_fantasia')
            ->get();

        if ($empresas->isEmpty()) {
            $this->error('Nenhuma empresa encontrada.');

            return self::FAILURE;
        }

        $simular = (bool) $this->option('simular');
        $criados = 0;
        $atualizados = 0;

        foreach ($empresas as $empresa) {
            $r = RelatoriosPadrao::completar($empresa, $simular);
            $criados += count($r['criados']);
            $atualizados += count($r['atualizados']);

            $partes = array_filter([
                $r['criados'] ? ($simular ? 'criaria ' : 'criados ').implode(', ', $r['criados']) : null,
                $r['atualizados'] ? ($simular ? 'atualizaria ' : 'atualizados ').implode(', ', $r['atualizados']) : null,
            ]);
            $this->line($empresa->nome_fantasia.': '.($partes ? implode('; ', $partes) : 'já estava em dia'));
        }

        $this->info(sprintf(
            '%s %d e %s %d relatório(s) em %d empresa(s).',
            $simular ? 'Seriam criados' : 'Criados',
            $criados,
            $simular ? 'atualizados' : 'atualizados',
            $atualizados,
            $empresas->count(),
        ));

        return self::SUCCESS;
    }
}

<?php

namespace App\Console\Commands;

use App\Models\Empresa;
use App\Support\ParametrosPadrao;
use Illuminate\Console\Command;

/**
 * Cadastra, nas empresas que ainda não têm, os parâmetros do catálogo `App\Support\ParametrosPadrao`
 * (com o mesmo valor que o sistema já assume quando falta — não muda comportamento, só deixa
 * visível/editável na tela Parâmetros). Nunca sobrescreve nem reativa parâmetro existente.
 * Mesmo efeito do botão "Completar parâmetros" no detalhe da empresa do superadmin, só que em lote.
 *
 *   php artisan parametros:completar                 # todas as empresas ativas
 *   php artisan parametros:completar --empresa=UUID  # uma empresa só
 *   php artisan parametros:completar --simular       # só mostra o que faltaria, não grava
 */
class CompletarParametros extends Command
{
    protected $signature = 'parametros:completar
        {--empresa= : UUID de uma empresa específica (padrão: todas as ativas)}
        {--simular : Só lista o que seria criado, sem gravar nada}';

    protected $description = 'Cadastra nas empresas os parâmetros padrão que ainda faltam (sem sobrescrever os existentes)';

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
        $total = 0;

        foreach ($empresas as $empresa) {
            $faltando = collect(ParametrosPadrao::situacao($empresa))->where('cadastrado', false)->pluck('chave')->all();
            $criadas = $simular ? $faltando : ParametrosPadrao::completar($empresa);
            $total += count($criadas);

            $this->line(sprintf(
                '%s: %s',
                $empresa->nome_fantasia,
                $criadas ? ($simular ? 'faltam ' : 'criados ').implode(', ', $criadas) : 'já tinha todos',
            ));
        }

        $this->info(sprintf(
            '%s %d parâmetro(s) em %d empresa(s).',
            $simular ? 'Seriam criados' : 'Criados',
            $total,
            $empresas->count(),
        ));

        return self::SUCCESS;
    }
}

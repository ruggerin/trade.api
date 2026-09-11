<?php

namespace Database\Factories;

use App\Enums\OrigemOrdemServico;
use App\Enums\StatusOrdemServico;
use App\Models\Empresa;
use App\Models\OrdemServico;
use App\Models\PontoVenda;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrdemServico>
 */
class OrdemServicoFactory extends Factory
{
    protected $model = OrdemServico::class;

    public function definition(): array
    {
        return [
            'empresa_id' => Empresa::factory(),
            'ponto_venda_id' => PontoVenda::factory(),
            'usuario_id' => null,
            'origem' => OrigemOrdemServico::MANUAL,
            'obrigatoria' => true,
            'prazo_inicio' => now(),
            'prazo_fim' => now()->addDays(3),
            'status' => StatusOrdemServico::PENDENTE,
        ];
    }

    public function expirada(): static
    {
        return $this->state([
            'prazo_inicio' => now()->subDays(5),
            'prazo_fim' => now()->subDay(),
        ]);
    }

    public function concluida(): static
    {
        return $this->state(['status' => StatusOrdemServico::CONCLUIDA]);
    }

    public function cancelada(): static
    {
        return $this->state(['status' => StatusOrdemServico::CANCELADA]);
    }
}

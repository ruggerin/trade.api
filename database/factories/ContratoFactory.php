<?php

namespace Database\Factories;

use App\Enums\TipoContrato;
use App\Models\Contrato;
use App\Models\Empresa;
use App\Models\PontoVenda;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Contrato>
 */
class ContratoFactory extends Factory
{
    protected $model = Contrato::class;

    public function definition(): array
    {
        return [
            'empresa_id' => Empresa::factory(),
            'ponto_venda_id' => PontoVenda::factory(),
            'tipo' => TipoContrato::COMODATO,
            'vigencia_inicio' => now()->subMonths(6),
            'vigencia_fim' => now()->addMonths(6),
            'ativo' => true,
        ];
    }

    public function vencendoEm(int $dias): static
    {
        return $this->state(['vigencia_fim' => now()->addDays($dias)]);
    }

    public function inativo(): static
    {
        return $this->state(['ativo' => false]);
    }
}

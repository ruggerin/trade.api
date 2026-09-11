<?php

namespace Database\Factories;

use App\Enums\CheckoutTipo;
use App\Enums\StatusVisita;
use App\Models\Empresa;
use App\Models\PontoVenda;
use App\Models\Usuario;
use App\Models\Visita;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Visita>
 */
class VisitaFactory extends Factory
{
    protected $model = Visita::class;

    public function definition(): array
    {
        return [
            'empresa_id' => Empresa::factory(),
            'ponto_venda_id' => PontoVenda::factory(),
            'usuario_id' => Usuario::factory()->promotor(),
            'status' => StatusVisita::ABERTA,
            'inicio_data' => now()->subHours(2),
            'inicio_latitude' => -23.55,
            'inicio_longitude' => -46.63,
            'inicio_distancia_metros' => 12.5,
        ];
    }

    public function finalizada(): static
    {
        return $this->state(fn () => [
            'status' => StatusVisita::FINALIZADA,
            'fim_data' => now()->subHour(),
            'fim_latitude' => -23.55,
            'fim_longitude' => -46.63,
            'fim_distancia_metros' => 20.0,
            'checkout_tipo' => CheckoutTipo::PROMOTOR,
        ]);
    }

    public function cancelada(): static
    {
        return $this->state(['status' => StatusVisita::CANCELADA]);
    }
}

<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Municipio;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Municipio>
 */
class MunicipioFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'codigo_ibge' => (string) fake()->unique()->numberBetween(1_000_000, 5_999_999),
            'nome' => fake()->city(),
            'uf' => fake()->randomElement(['SP', 'MG', 'BA', 'RS', 'PE']),
            'nivel' => (string) fake()->numberBetween(0, 3),
        ];
    }

    /** O município usado no desenvolvimento: 31 anos de acervo, sem lacunas. */
    public function campinas(): static
    {
        return $this->state([
            'codigo_ibge' => '3509502',
            'nome' => 'Campinas',
            'uf' => 'SP',
            'nivel' => '3',
        ]);
    }
}

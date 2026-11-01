<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\StatusDiario;
use App\Models\Diario;
use App\Models\Municipio;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Diario>
 */
class DiarioFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $data = fake()->dateTimeBetween('-2 years')->format('Y-m-d');
        $hash = fake()->unique()->sha1();

        return [
            'municipio_id' => Municipio::factory(),
            'data_publicacao' => $data,
            'edicao' => (string) fake()->numberBetween(10_000, 14_000),
            'edicao_extra' => false,
            'url_original' => "https://data.queridodiario.ok.org.br/3509502/{$data}/{$hash}.pdf",
            'url_texto' => "https://data.queridodiario.ok.org.br/3509502/{$data}/{$hash}.txt",
            'scraped_at' => now(),
            'status' => StatusDiario::Pendente,
        ];
    }

    /** Diário que só existe em PDF: a API não oferece versão em texto. */
    public function semTexto(): static
    {
        return $this->state([
            'url_texto' => null,
            'status' => StatusDiario::SemTexto,
        ]);
    }

    public function baixado(): static
    {
        return $this->state(fn (array $atributos): array => [
            'status' => StatusDiario::Baixado,
            'caminho_arquivo_local' => 'diarios/3509502/'.$atributos['data_publicacao'].'_'.$atributos['edicao'].'.txt',
            'bytes' => fake()->numberBetween(50_000, 1_200_000),
            'hash_sha256' => hash('sha256', fake()->unique()->uuid()),
        ]);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature;

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class VerificarCoberturaTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('querido_diario.requisicoes_por_minuto', 0);
        config()->set('querido_diario.cache_minutos', 0);

        Http::preventStrayRequests();
    }

    public function test_reprova_municipio_cadastrado_porem_sem_nenhum_diario(): void
    {
        // O caso de Franca/SP: aparece em /cities, tem nível 1, e acervo zerado.
        $this->fingirApi(municipio: ['territory_name' => 'Franca', 'state_code' => 'SP', 'level' => '1'], total: 0);

        $this->artisan('diario:cobertura', ['--ibge' => '3516200'])
            ->expectsOutputToContain('ACERVO VAZIO')
            ->assertExitCode(1);
    }

    public function test_reprova_codigo_ibge_inexistente(): void
    {
        Http::fake(['*' => Http::response(status: 404)]);

        $this->artisan('diario:cobertura', ['--ibge' => '0000000'])
            ->expectsOutputToContain('Não existe município')
            ->assertExitCode(1);
    }

    public function test_aprova_municipio_com_acervo_ativo(): void
    {
        $this->fingirApi(
            municipio: ['territory_name' => 'Campinas', 'state_code' => 'SP', 'level' => '3'],
            total: 7_697,
            maisRecente: now()->subDays(2)->format('Y-m-d'),
        );

        $this->artisan('diario:cobertura', ['--ibge' => '3509502', '--rapido' => true])
            ->expectsOutputToContain('município utilizável')
            ->assertExitCode(0);
    }

    public function test_avisa_quando_o_raspador_esta_parado_ha_muito_tempo(): void
    {
        // O caso de Lauro de Freitas/BA: 3.264 diários, nenhum há mais de um ano.
        $this->fingirApi(
            municipio: ['territory_name' => 'Lauro de Freitas', 'state_code' => 'BA', 'level' => '3'],
            total: 3_264,
            maisRecente: now()->subMonths(16)->format('Y-m-d'),
        );

        $this->artisan('diario:cobertura', ['--ibge' => '2919207', '--rapido' => true])
            ->expectsOutputToContain('acervo existe, mas está desatualizado')
            ->assertExitCode(0);
    }

    public function test_rejeita_data_em_formato_invalido(): void
    {
        Http::fake();

        $this->artisan('diario:cobertura', ['--ibge' => '3509502', '--desde' => '31/10/2026'])
            ->expectsOutputToContain('AAAA-MM-DD')
            ->assertExitCode(1);
    }

    /**
     * @param  array<string, mixed>  $municipio
     */
    private function fingirApi(array $municipio, int $total, string $maisRecente = '2026-10-29'): void
    {
        Http::fake(function (Request $request) use ($municipio, $total, $maisRecente): PromiseInterface {
            if (str_contains($request->url(), '/cities/')) {
                return Http::response(['city' => $municipio]);
            }

            $diarios = $total > 0
                ? [['date' => $maisRecente, 'txt_url' => 'https://exemplo/diario.txt', 'is_extra_edition' => false]]
                : [];

            return Http::response(['total_gazettes' => $total, 'gazettes' => $diarios]);
        });
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\QueridoDiarioClient;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class QueridoDiarioClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    public function test_busca_tolerante_encontra_municipio_acentuado_a_partir_de_termo_sem_acento(): void
    {
        Http::fake(function (Request $request): PromiseInterface {
            $termo = $this->parametro($request, 'city_name');

            // A API casa por substring e ignora maiúsculas, mas não ignora acento.
            if ($termo === 'jaguari') {
                return Http::response(['cities' => [
                    ['territory_id' => '2305902', 'territory_name' => 'Jaguaribe', 'state_code' => 'CE'],
                    ['territory_id' => '3524709', 'territory_name' => 'Jaguariúna', 'state_code' => 'SP'],
                ]]);
            }

            return Http::response(['cities' => []]);
        });

        $resultado = $this->cliente()->buscarMunicipiosTolerandoAcento('jaguariuna');

        $this->assertSame('jaguari', $resultado['termo_usado']);
        $this->assertCount(1, $resultado['municipios']);
        $this->assertSame('3524709', $resultado['municipios'][0]['territory_id']);
    }

    public function test_busca_tolerante_nao_encurta_o_termo_quando_a_busca_exata_funciona(): void
    {
        Http::fake(['*' => Http::response(['cities' => [
            ['territory_id' => '3509502', 'territory_name' => 'Campinas', 'state_code' => 'SP'],
        ]])]);

        $resultado = $this->cliente()->buscarMunicipiosTolerandoAcento('Campinas');

        $this->assertSame('Campinas', $resultado['termo_usado']);
        Http::assertSentCount(1);
    }

    public function test_coleta_cronologica_ordena_por_data_crescente(): void
    {
        Http::fake(['*' => Http::response(['total_gazettes' => 0, 'gazettes' => []])]);

        $this->cliente()->buscarDiarios('3509502');

        Http::assertSent(fn (Request $request): bool => $this->parametro($request, 'sort_by') === 'ascending_date');
    }

    public function test_percorrer_diarios_visita_todas_as_paginas_sem_repetir(): void
    {
        $total = 2_500;

        Http::fake(function (Request $request) use ($total): PromiseInterface {
            $deslocamento = (int) $this->parametro($request, 'offset');
            $restantes = max(0, $total - $deslocamento);
            $nesta = min(QueridoDiarioClient::TAMANHO_MAXIMO_PAGINA, $restantes);

            return Http::response([
                'total_gazettes' => $total,
                'gazettes' => array_map(
                    static fn (int $i): array => ['date' => '2026-01-01', 'txt_url' => 'https://exemplo/'.($deslocamento + $i)],
                    range(0, max(0, $nesta - 1)),
                ),
            ]);
        });

        $urls = [];

        foreach ($this->cliente()->percorrerDiarios('3509502') as $diario) {
            $urls[] = $diario['txt_url'];
        }

        $this->assertCount($total, $urls);
        $this->assertCount($total, array_unique($urls), 'A paginação repetiu registros.');
        Http::assertSentCount(3);
    }

    public function test_percorrer_diarios_para_quando_a_api_devolve_pagina_vazia(): void
    {
        // Total inconsistente com o que a API entrega: sem a guarda de página
        // vazia, o laço rodaria para sempre.
        Http::fake(['*' => Http::response(['total_gazettes' => 5_000, 'gazettes' => []])]);

        $visitados = iterator_to_array($this->cliente()->percorrerDiarios('3509502'), false);

        $this->assertSame([], $visitados);
        Http::assertSentCount(1);
    }

    public function test_obter_municipio_devolve_null_quando_o_codigo_nao_existe(): void
    {
        Http::fake(['*' => Http::response(status: 404)]);

        $this->assertNull($this->cliente()->obterMunicipio('0000000'));
    }

    private function cliente(): QueridoDiarioClient
    {
        return new QueridoDiarioClient(
            urlBase: 'https://api.queridodiario.org.br',
            requisicoesPorMinuto: 0,
            timeout: 5,
            timeoutConexao: 2,
            cacheMinutos: 0,
        );
    }

    private function parametro(Request $request, string $nome): ?string
    {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $parametros);

        return isset($parametros[$nome]) ? (string) $parametros[$nome] : null;
    }
}

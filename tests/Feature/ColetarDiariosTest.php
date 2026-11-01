<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\StatusDiario;
use App\Models\Diario;
use App\Models\Municipio;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ColetarDiariosTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('querido_diario.requisicoes_por_minuto', 0);
        config()->set('querido_diario.cache_minutos', 0);

        Http::preventStrayRequests();
    }

    public function test_rodar_duas_vezes_nao_duplica_diarios(): void
    {
        $this->fingirApi([
            $this->diario('2026-10-01', '13920'),
            $this->diario('2026-10-05', '13922'),
        ]);

        $this->artisan('diario:coletar', ['--ibge' => '3509502'])->assertExitCode(0);
        $this->artisan('diario:coletar', ['--ibge' => '3509502'])->assertExitCode(0);

        $this->assertSame(2, Diario::count());
        $this->assertSame(1, Municipio::count());
    }

    public function test_recoletar_nao_rebaixa_o_status_de_um_diario_ja_baixado(): void
    {
        // O risco real: um updateOrCreate ingênuo devolveria o diário para
        // "pendente" e mandaria baixar tudo de novo a cada coleta.
        $diario = $this->diario('2026-10-01', '13920');

        $this->fingirApi([$diario]);
        $this->artisan('diario:coletar', ['--ibge' => '3509502']);

        Diario::query()->update([
            'status' => StatusDiario::Baixado,
            'caminho_arquivo_local' => 'x.txt',
        ]);

        $this->artisan('diario:coletar', ['--ibge' => '3509502']);

        $this->assertSame(StatusDiario::Baixado, Diario::sole()->status);
        $this->assertSame('x.txt', Diario::sole()->caminho_arquivo_local);
    }

    public function test_diario_sem_texto_e_registrado_como_sem_texto_e_nao_como_erro(): void
    {
        $semTexto = $this->diario('2026-10-01', '13920');
        $semTexto['txt_url'] = null;

        $this->fingirApi([$semTexto, $this->diario('2026-10-05', '13922')]);

        $this->artisan('diario:coletar', ['--ibge' => '3509502'])->assertExitCode(0);

        $this->assertSame(1, Diario::where('status', StatusDiario::SemTexto)->count());
        $this->assertSame(1, Diario::where('status', StatusDiario::Pendente)->count());
        $this->assertSame(0, Diario::where('status', StatusDiario::Erro)->count());
    }

    public function test_guarda_dois_diarios_com_a_mesma_data_e_edicao(): void
    {
        // Encontrado nos dados reais: em mil diários de Campinas há apenas 997
        // pares data+edição distintos. A chave única é a URL, não o par.
        $primeiro = $this->diario('2025-12-31', '13737');
        $segundo = $this->diario('2025-12-31', '13737');
        $segundo['url'] = 'https://data.queridodiario.ok.org.br/3509502/2025-12-31/outro.pdf';

        $this->fingirApi([$primeiro, $segundo]);

        $this->artisan('diario:coletar', ['--ibge' => '3509502'])->assertExitCode(0);

        $this->assertSame(2, Diario::count());
    }

    public function test_guarda_o_retrato_da_cobertura_no_municipio(): void
    {
        $this->fingirApi([$this->diario('2026-10-01', '13920')]);

        $this->artisan('diario:coletar', ['--ibge' => '3509502'])->assertExitCode(0);

        $municipio = Municipio::sole();

        $this->assertSame('Campinas', $municipio->nome);
        $this->assertSame('SP', $municipio->uf);
        $this->assertSame(1, $municipio->total_diarios_api);
        $this->assertNotNull($municipio->verificado_em);
    }

    /**
     * @return array<string, mixed>
     */
    private function diario(string $data, string $edicao): array
    {
        return [
            'territory_id' => '3509502',
            'date' => $data,
            'edition' => $edicao,
            'is_extra_edition' => false,
            'scraped_at' => '2026-10-30T03:00:00Z',
            'url' => "https://data.queridodiario.ok.org.br/3509502/{$data}/{$edicao}.pdf",
            'txt_url' => "https://data.queridodiario.ok.org.br/3509502/{$data}/{$edicao}.txt",
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $diarios
     */
    private function fingirApi(array $diarios): void
    {
        Http::fake(function (Request $request) use ($diarios): PromiseInterface {
            if (str_contains($request->url(), '/cities/')) {
                return Http::response(['city' => [
                    'territory_id' => '3509502',
                    'territory_name' => 'Campinas',
                    'state_code' => 'SP',
                    'level' => '3',
                ]]);
            }

            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $parametros);
            $deslocamento = (int) ($parametros['offset'] ?? 0);

            return Http::response([
                'total_gazettes' => count($diarios),
                'gazettes' => array_slice($diarios, $deslocamento),
            ]);
        });
    }
}

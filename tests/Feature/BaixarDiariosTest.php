<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\StatusDiario;
use App\Models\Diario;
use App\Models\Municipio;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BaixarDiariosTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('querido_diario.pausa_download_ms', 0);

        Storage::fake('diarios');
        Http::preventStrayRequests();
    }

    public function test_grava_o_texto_em_disco_com_tamanho_e_hash(): void
    {
        $texto = "DECRETO Nº 123\nCampinas, 29 de outubro de 2026\n";
        Http::fake(['*' => Http::response($texto)]);

        $diario = $this->diarioPendente('2026-10-29', '13939');

        $this->artisan('diario:baixar', ['--ibge' => '3509502'])->assertExitCode(0);

        $diario->refresh();

        $this->assertSame(StatusDiario::Baixado, $diario->status);
        $this->assertSame(strlen($texto), $diario->bytes);
        $this->assertSame(hash('sha256', $texto), $diario->hash_sha256);
        Storage::disk('diarios')->assertExists((string) $diario->caminho_arquivo_local);
    }

    public function test_marca_erro_e_nao_deixa_arquivo_pela_metade_quando_o_servidor_falha(): void
    {
        Http::fake(['*' => Http::response('', 500)]);

        $diario = $this->diarioPendente('2026-10-29', '13939');

        $this->artisan('diario:baixar', ['--ibge' => '3509502'])->assertExitCode(0);

        $diario->refresh();

        $this->assertSame(StatusDiario::Erro, $diario->status);
        $this->assertStringContainsString('500', (string) $diario->erro_mensagem);
        $this->assertNull($diario->caminho_arquivo_local);
        $this->assertSame([], Storage::disk('diarios')->allFiles());
    }

    public function test_diarios_com_mesma_data_e_edicao_nao_sobrescrevem_o_arquivo_um_do_outro(): void
    {
        Http::fake(['*' => Http::response('conteudo')]);

        $this->diarioPendente('2025-12-31', '13737', 'aaaaaaaa');
        $this->diarioPendente('2025-12-31', '13737', 'bbbbbbbb');

        $this->artisan('diario:baixar', ['--ibge' => '3509502'])->assertExitCode(0);

        $caminhos = Diario::pluck('caminho_arquivo_local')->all();

        $this->assertCount(2, array_unique($caminhos));
        $this->assertCount(2, Storage::disk('diarios')->allFiles());
    }

    public function test_ignora_diario_que_nao_tem_versao_em_texto(): void
    {
        Http::fake();

        $diario = Diario::factory()->semTexto()->for($this->municipio())->create();

        $this->artisan('diario:baixar', ['--ibge' => '3509502'])
            ->expectsOutputToContain('Nada a baixar')
            ->assertExitCode(0);

        $this->assertSame(StatusDiario::SemTexto, $diario->refresh()->status);
        Http::assertNothingSent();
    }

    private function diarioPendente(string $data, string $edicao, string $hash = 'abc12345'): Diario
    {
        return Diario::factory()->for($this->municipio())->create([
            'data_publicacao' => $data,
            'edicao' => $edicao,
            'status' => StatusDiario::Pendente,
            'url_original' => "https://data.queridodiario.ok.org.br/3509502/{$data}/{$hash}.pdf",
            'url_texto' => "https://data.queridodiario.ok.org.br/3509502/{$data}/{$hash}.txt",
        ]);
    }

    private function municipio(): Municipio
    {
        return Municipio::firstOrCreate(
            ['codigo_ibge' => '3509502'],
            ['nome' => 'Campinas', 'uf' => 'SP', 'nivel' => '3'],
        );
    }
}

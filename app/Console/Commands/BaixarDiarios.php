<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ResolveOpcoesComuns;
use App\Enums\StatusDiario;
use App\Models\Diario;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Throwable;

#[Signature('diario:baixar
    {--ibge= : Código IBGE do município (padrão: QD_TERRITORY_ID do .env)}
    {--limite= : Quantos diários baixar nesta execução}')]
#[Description('Baixa o texto dos diários pendentes e grava em disco')]
class BaixarDiarios extends Command
{
    use ResolveOpcoesComuns;

    public function handle(): int
    {
        $codigoIbge = $this->codigoIbgeInformado();

        if ($codigoIbge === null) {
            return self::FAILURE;
        }

        $limite = $this->option('limite');

        $pendentes = Diario::query()
            ->aguardandoDownload()
            ->whereRelation('municipio', 'codigo_ibge', $codigoIbge)
            ->with('municipio')
            ->orderBy('data_publicacao')
            ->orderBy('id')
            ->when($limite !== null, fn ($consulta) => $consulta->limit((int) $limite))
            ->get();

        if ($pendentes->isEmpty()) {
            $this->info('Nada a baixar: nenhum diário pendente com texto disponível.');

            return self::SUCCESS;
        }

        $this->line('Baixando '.$this->numero($pendentes->count()).' diários...');

        $barra = $this->output->createProgressBar($pendentes->count());
        $barra->start();

        $baixados = 0;
        $bytesTotais = 0;
        $falhas = [];
        $pausa = (int) config('querido_diario.pausa_download_ms');

        foreach ($pendentes as $indice => $diario) {
            if ($indice > 0 && $pausa > 0) {
                usleep($pausa * 1_000);
            }

            $erro = $this->baixar($diario);

            if ($erro === null) {
                $baixados++;
                $bytesTotais += (int) $diario->bytes;
            } else {
                $falhas[] = $diario->data_publicacao->format('Y-m-d').' — '.$erro;
            }

            $barra->advance();
        }

        $barra->finish();
        $this->newLine(2);

        $this->line('Baixados: '.$this->numero($baixados).' ('.$this->tamanhoLegivel($bytesTotais).')');

        if ($falhas !== []) {
            $this->newLine();
            $this->warn($this->numero(count($falhas)).' falha(s), marcadas como "erro" para nova tentativa:');

            foreach (array_slice($falhas, 0, 10) as $falha) {
                $this->line('  '.$falha);
            }

            if (count($falhas) > 10) {
                $this->line('  ... e mais '.$this->numero(count($falhas) - 10).'.');
            }
        }

        return self::SUCCESS;
    }

    /**
     * Baixa um diário por streaming. Devolve null em caso de sucesso, ou a
     * mensagem de erro.
     */
    private function baixar(Diario $diario): ?string
    {
        $disco = Storage::disk('diarios');
        $caminho = $this->caminhoDoArquivo($diario);
        $disco->makeDirectory(dirname($caminho));
        $absoluto = $disco->path($caminho);

        try {
            // sink() grava direto no arquivo, sem montar o corpo inteiro na
            // memória: um diário de Campinas tem mais de 1 MB.
            $resposta = Http::connectTimeout((int) config('querido_diario.timeout_conexao'))
                ->timeout((int) config('querido_diario.timeout_download'))
                ->retry([1_000, 5_000], when: static fn (Throwable $e): bool => $e instanceof ConnectionException
                    || ($e instanceof RequestException && $e->response->serverError()),
                    throw: false)
                ->sink($absoluto)
                ->get((string) $diario->url_texto);

            if ($resposta->failed()) {
                $disco->delete($caminho);

                return $this->registrarErro($diario, 'HTTP '.$resposta->status());
            }
        } catch (Throwable $excecao) {
            $disco->delete($caminho);

            return $this->registrarErro($diario, $excecao->getMessage());
        }

        $diario->forceFill([
            'caminho_arquivo_local' => $caminho,
            'bytes' => filesize($absoluto) ?: 0,
            'hash_sha256' => hash_file('sha256', $absoluto),
            'status' => StatusDiario::Baixado,
            'erro_mensagem' => null,
        ])->save();

        return null;
    }

    private function registrarErro(Diario $diario, string $mensagem): string
    {
        $diario->forceFill([
            'status' => StatusDiario::Erro,
            'erro_mensagem' => $mensagem,
        ])->save();

        return $mensagem;
    }

    /**
     * Monta o caminho do arquivo dentro do disco "diarios".
     *
     * O plano previa {data}_{edicao}.txt, mas isso colide: existem diários
     * diferentes com a mesma data e o mesmo número de edição. Por isso entra
     * também o começo do hash que a própria URL de origem carrega, que é
     * único e permite voltar ao arquivo original.
     */
    private function caminhoDoArquivo(Diario $diario): string
    {
        $edicao = preg_replace('/[^A-Za-z0-9_-]/', '', (string) $diario->edicao);
        $edicao = $edicao !== '' ? $edicao : 'sem-edicao';

        $nomeOrigem = pathinfo((string) $diario->url_original, PATHINFO_FILENAME);
        $marca = substr(preg_replace('/[^a-f0-9]/i', '', $nomeOrigem) ?: md5((string) $diario->url_original), 0, 8);

        return sprintf(
            '%s/%s_%s_%s.txt',
            $diario->municipio->codigo_ibge,
            $diario->data_publicacao->format('Y-m-d'),
            $edicao,
            $marca,
        );
    }
}

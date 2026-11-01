<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\QueridoDiarioClient;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

#[Signature('diario:municipio {nome : Nome do município, com ou sem acento}')]
#[Description('Descobre o código IBGE de um município no Querido Diário')]
class BuscarMunicipio extends Command
{
    public function handle(QueridoDiarioClient $cliente): int
    {
        $nome = trim((string) $this->argument('nome'));

        if ($nome === '') {
            $this->error('Informe o nome do município.');

            return self::FAILURE;
        }

        $this->line("Procurando \"{$nome}\"...");

        $resultado = $cliente->buscarMunicipiosTolerandoAcento($nome);
        $municipios = $resultado['municipios'];

        if ($municipios === []) {
            $this->error("Nenhum município encontrado para \"{$nome}\".");
            $this->line('A busca da API é sensível a acento: "Jaguariúna" encontra, "jaguariuna" não.');
            $this->line('Tente escrever o nome acentuado, ou só o começo dele.');

            return self::FAILURE;
        }

        if ($resultado['termo_usado'] !== $nome) {
            $this->warn(
                'A busca exata não achou nada, porque a API é sensível a acento. '.
                "Encontrei procurando por \"{$resultado['termo_usado']}\"."
            );
        }

        $this->newLine();
        $this->table(
            ['Código IBGE', 'Município', 'UF', 'Nível'],
            array_map(static fn (array $municipio): array => [
                $municipio['territory_id'] ?? '-',
                $municipio['territory_name'] ?? '-',
                $municipio['state_code'] ?? '-',
                $municipio['level'] ?? '-',
            ], $municipios),
        );

        $this->warn('O "nível" NÃO garante que existam diários coletados.');
        $this->line('Municípios com nível alto já apareceram com acervo vazio ou parado há mais de um ano.');
        $this->newLine();

        $escolhido = $this->correspondenciaExata($municipios, $nome);

        if ($escolhido !== null) {
            $this->line('Confirme antes de usar:');
            $this->line("  <fg=cyan>php artisan diario:cobertura --ibge={$escolhido}</>");
        } else {
            $this->line('Escolha o código da lista e confirme antes de usar:');
            $this->line('  <fg=cyan>php artisan diario:cobertura --ibge={CODIGO}</>');
        }

        return self::SUCCESS;
    }

    /**
     * Devolve o código IBGE do município cujo nome bate exatamente com o termo.
     *
     * Buscar "Franca" devolve também "Passagem Franca", porque a API casa por
     * substring. Sugerir o primeiro da lista mandaria o usuário para a cidade
     * errada, então só sugerimos quando há uma correspondência exata e única.
     *
     * @param  array<int, array<string, mixed>>  $municipios
     */
    private function correspondenciaExata(array $municipios, string $nome): ?string
    {
        $alvo = Str::lower(Str::ascii($nome));

        $exatos = array_values(array_filter(
            $municipios,
            static fn (array $municipio): bool => Str::lower(Str::ascii((string) ($municipio['territory_name'] ?? ''))) === $alvo,
        ));

        return count($exatos) === 1
            ? (string) $exatos[0]['territory_id']
            : null;
    }
}

<?php

declare(strict_types=1);

namespace App\Console\Commands\Concerns;

use Carbon\CarbonImmutable;
use Throwable;

/**
 * Opções que quase todo comando do pipeline recebe: qual município e qual período.
 */
trait ResolveOpcoesComuns
{
    /**
     * Código IBGE vindo de --ibge ou do .env, ou null se nenhum dos dois veio.
     */
    protected function codigoIbgeInformado(): ?string
    {
        $codigo = trim((string) ($this->option('ibge') ?? config('querido_diario.codigo_ibge') ?? ''));

        if ($codigo === '') {
            $this->error('Informe o município com --ibge=, ou defina QD_TERRITORY_ID no .env.');
            $this->line('Para descobrir o código: php artisan diario:municipio {nome}');

            return null;
        }

        return $codigo;
    }

    /**
     * @return string|null|false `false` quando a data veio em formato inválido.
     */
    protected function lerData(string $opcao): string|null|false
    {
        $valor = $this->option($opcao);

        if ($valor === null || $valor === '') {
            return null;
        }

        try {
            return CarbonImmutable::createFromFormat('Y-m-d', (string) $valor)->format('Y-m-d');
        } catch (Throwable) {
            $this->error("A opção --{$opcao} precisa estar no formato AAAA-MM-DD. Recebi \"{$valor}\".");

            return false;
        }
    }

    protected function numero(int $valor): string
    {
        return number_format($valor, 0, ',', '.');
    }

    protected function tamanhoLegivel(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes.' B';
        }

        $unidades = ['KB', 'MB', 'GB'];
        $valor = $bytes / 1024;
        $indice = 0;

        while ($valor >= 1024 && $indice < count($unidades) - 1) {
            $valor /= 1024;
            $indice++;
        }

        return number_format($valor, 1, ',', '.').' '.$unidades[$indice];
    }
}

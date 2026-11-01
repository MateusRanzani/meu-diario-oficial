<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Segmenta o texto de um diário em blocos.
 *
 * Ordem de precedência (a primeira regra que casa "ganha" a linha):
 * 1. Tabular (2+ tabs) → bloco "tabela" — nunca vai para a IA
 * 2. CAIXA ALTA com comprimento razoável → provável início de ato ou seção
 * 3. Assinatura "Cidade, dd de mês de AAAA" → delimita o fim de um ato
 * 4. Linha em branco → potencial fronteira de bloco
 */
class SegmentadorDiario
{
    /**
     * @param  string[]  $linhas
     * @return array<int, array{
     *     tipo: string,
     *     linha_inicio: int,
     *     linha_fim: int,
     *     byte_inicio: int,
     *     byte_fim: int,
     *     texto: string,
     *     enviar_para_ia: bool,
     * }>
     */
    public function segmentar(array $linhas): array
    {
        $blocos = [];
        $linhaAtual = 0;
        $byteAtual = 0;
        $inicioBloco = 0;
        $byteInicioBloco = 0;
        $tipoAtual = 'texto';
        $linhasBloco = [];

        $fechar = function (int $fimLinha) use (&$blocos, &$linhasBloco, &$tipoAtual, &$inicioBloco, &$byteInicioBloco, &$byteAtual): void {
            if ($linhasBloco === []) {
                return;
            }

            $texto = implode('', $linhasBloco);

            if (trim($texto) === '') {
                $linhasBloco = [];

                return;
            }

            $blocos[] = [
                'tipo' => $tipoAtual,
                'linha_inicio' => $inicioBloco,
                'linha_fim' => $fimLinha - 1,
                'byte_inicio' => $byteInicioBloco,
                'byte_fim' => $byteAtual - 1,
                'texto' => $texto,
                'enviar_para_ia' => $tipoAtual !== 'tabela' && $tipoAtual !== 'cabecalho_pagina',
            ];

            $linhasBloco = [];
        };

        foreach ($linhas as $indice => $linha) {
            $tamanho = strlen($linha);
            $limpa = trim($linha);
            $novoTipo = $this->classificarLinha($limpa);

            $virou = $novoTipo !== $tipoAtual && $limpa !== '';

            if ($virou) {
                $fechar($indice);
                $tipoAtual = $novoTipo;
                $inicioBloco = $indice;
                $byteInicioBloco = $byteAtual;
            }

            $linhasBloco[] = $linha;
            $byteAtual += $tamanho;
            $linhaAtual = $indice;
        }

        $fechar($linhaAtual + 1);

        return array_values($blocos);
    }

    private function classificarLinha(string $linha): string
    {
        if ($linha === '') {
            return 'vazio';
        }

        if (substr_count($linha, "\t") >= 2) {
            return 'tabela';
        }

        if (preg_match('/^.{3,40},\s+\d{1,2}\s+de\s+\p{L}+\s+de\s+\d{4}\.?$/u', $linha) === 1) {
            return 'assinatura';
        }

        if (preg_match('/\p{L}/u', $linha) === 1 && mb_strtoupper($linha) === $linha && mb_strlen($linha) >= 5) {
            return 'titulo_secao';
        }

        return 'ato';
    }
}

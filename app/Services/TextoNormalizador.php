<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Limpa o texto extraído de PDF sem destruir os offsets de posição.
 *
 * A abordagem é preservar os índices de linha para que o segmentador consiga
 * mapear blocos no arquivo original. Por isso as transformações que envolvem
 * juntar linhas (hifenização) adicionam uma linha vazia no lugar das linhas
 * removidas, mantendo o número total de linhas igual.
 */
class TextoNormalizador
{
    /**
     * Frequência mínima para uma linha ser considerada cabeçalho/rodapé de página.
     */
    private const MINIMA_REPETICAO_CABECALHO = 5;

    /**
     * @param  string[]  $linhas
     * @return string[]
     */
    public function normalizar(array $linhas): array
    {
        $linhas = $this->removerCabecalhosRodapes($linhas);
        $linhas = $this->juntarHifenizadas($linhas);
        $linhas = $this->colapsarEspacoDuplo($linhas);

        return $linhas;
    }

    /**
     * Identifica e apaga linhas que parecem cabeçalho/rodapé de página.
     *
     * Uma linha que aparece 5+ vezes no mesmo diário é quase certo que é
     * repetida por paginação do PDF, não conteúdo real.
     *
     * @param  string[]  $linhas
     * @return string[]
     */
    private function removerCabecalhosRodapes(array $linhas): array
    {
        $contagem = [];

        foreach ($linhas as $linha) {
            $limpa = trim($linha);

            if ($limpa !== '') {
                $contagem[$limpa] = ($contagem[$limpa] ?? 0) + 1;
            }
        }

        $paresRemover = array_filter($contagem, static fn (int $n): bool => $n >= self::MINIMA_REPETICAO_CABECALHO);

        if ($paresRemover === []) {
            return $linhas;
        }

        return array_map(
            static fn (string $linha): string => isset($paresRemover[trim($linha)]) ? '' : $linha,
            $linhas,
        );
    }

    /**
     * Junta palavras quebradas por hifenização no final de linha.
     *
     * "ocor-\nrências" → "ocorrências\n\n"
     * A linha seguinte fica vazia para preservar o total de linhas.
     *
     * @param  string[]  $linhas
     * @return string[]
     */
    private function juntarHifenizadas(array $linhas): array
    {
        $resultado = [];
        $i = 0;
        $total = count($linhas);

        while ($i < $total) {
            $linha = $linhas[$i];
            $limpa = rtrim($linha);

            if (str_ends_with($limpa, '-') && isset($linhas[$i + 1])) {
                $proxima = ltrim($linhas[$i + 1]);
                $resultado[] = substr($limpa, 0, -1).$proxima;
                $resultado[] = '';
                $i += 2;
            } else {
                $resultado[] = $linha;
                $i++;
            }
        }

        return $resultado;
    }

    /**
     * Colapsa dois ou mais espaços seguidos em um único.
     *
     * @param  string[]  $linhas
     * @return string[]
     */
    private function colapsarEspacoDuplo(array $linhas): array
    {
        return array_map(
            static fn (string $linha): string => (string) preg_replace('/ {2,}/', ' ', $linha),
            $linhas,
        );
    }
}

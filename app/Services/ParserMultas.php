<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Bloco;
use App\Models\Diario;

/**
 * Parseia blocos tabulares de multas de trânsito.
 *
 * Formato real observado nos diários de Campinas (2 multas por linha):
 * QOR5827\tS157028957\t74550\t04/09/2025\t130,16\tQOR7550\tA075609922\t55412\t05/09/2025\t195,23
 *
 * Campos (0-indexado): placa | auto_infracao | codigo | data | valor
 * O mesmo padrão se repete para a segunda multa na mesma linha (índices 5-9).
 */
class ParserMultas
{
    private const COLUNAS_POR_MULTA = 5;

    private const INDICE_PLACA = 0;

    private const INDICE_AUTO = 1;

    private const INDICE_CODIGO = 2;

    private const INDICE_DATA = 3;

    private const INDICE_VALOR = 4;

    public function parsear(Diario $diario, Bloco $bloco, string $texto): void
    {
        foreach (explode("\n", $texto) as $linha) {
            $colunas = explode("\t", trim($linha));

            $this->parsearGrupo($diario, $bloco, $colunas, 0);
            $this->parsearGrupo($diario, $bloco, $colunas, self::COLUNAS_POR_MULTA);
        }
    }

    /** @param  string[]  $colunas */
    private function parsearGrupo(Diario $diario, Bloco $bloco, array $colunas, int $offset): void
    {
        if (count($colunas) < $offset + self::COLUNAS_POR_MULTA) {
            return;
        }

        $placa = trim($colunas[$offset + self::INDICE_PLACA] ?? '');
        $auto = trim($colunas[$offset + self::INDICE_AUTO] ?? '');
        $codigo = trim($colunas[$offset + self::INDICE_CODIGO] ?? '');
        $dataStr = trim($colunas[$offset + self::INDICE_DATA] ?? '');
        $valorStr = trim($colunas[$offset + self::INDICE_VALOR] ?? '');

        if ($placa === '' || ! preg_match('/^[A-Z]{3}\d{4}$|^[A-Z]{3}\d[A-Z]\d{2}$/', $placa)) {
            return;
        }

        $data = $this->parsearData($dataStr);
        $valor = $this->parsearValor($valorStr);

        $diario->multas()->create([
            'bloco_id' => $bloco->id,
            'placa' => $placa,
            'auto_infracao' => $auto ?: null,
            'codigo' => $codigo ?: null,
            'data_infracao' => $data,
            'valor' => $valor,
        ]);
    }

    private function parsearData(string $dataStr): ?string
    {
        if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $dataStr, $partes) !== 1) {
            return null;
        }

        return "{$partes[3]}-{$partes[2]}-{$partes[1]}";
    }

    private function parsearValor(string $valorStr): ?float
    {
        $limpo = str_replace(['.', ','], ['', '.'], $valorStr);

        return is_numeric($limpo) ? (float) $limpo : null;
    }
}

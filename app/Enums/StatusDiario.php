<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Onde cada diário está no pipeline.
 *
 * O status é o que torna o processamento retomável: uma queda no meio de um
 * mês de coleta não obriga a recomeçar, porque cada diário sabe até onde
 * chegou. Os estados seguem a ordem desta lista.
 */
enum StatusDiario: string
{
    /** Metadados registrados; o texto ainda não foi baixado. */
    case Pendente = 'pendente';

    /** Texto baixado e gravado em disco. */
    case Baixado = 'baixado';

    /**
     * A API não oferece versão em texto deste diário.
     *
     * Não é erro: é um fato sobre o dado. Só existe o PDF, então o pipeline
     * de texto não tem o que fazer com ele.
     */
    case SemTexto = 'sem_texto';

    /** Texto dividido em blocos e atos. (Fase 2) */
    case Segmentado = 'segmentado';

    /** Atos classificados e extraídos. (Fase 4) */
    case Processado = 'processado';

    /** Falhou de um jeito que precisa de olho humano. */
    case Erro = 'erro';

    public function rotulo(): string
    {
        return match ($this) {
            self::Pendente => 'pendente',
            self::Baixado => 'baixado',
            self::SemTexto => 'sem texto disponível',
            self::Segmentado => 'segmentado',
            self::Processado => 'processado',
            self::Erro => 'erro',
        };
    }
}

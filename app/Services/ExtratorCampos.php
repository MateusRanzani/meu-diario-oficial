<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Extrai campos estruturados de um bloco de texto usando regex.
 *
 * Todos os campos extraídos aqui são marcados com fonte_extracao = 'regex'.
 * Só o que o regex não consegue vai para a IA.
 */
class ExtratorCampos
{
    /**
     * Campos "Chave: valor" que aparecem com frequência nos diários.
     * A chave é o label do campo, o valor é o nome semântico no output.
     *
     * @var array<string, string>
     */
    private const CAMPOS_ESTRUTURADOS = [
        'Processo' => 'processo',
        'Processo Administrativo' => 'processo',
        'PROTOCOLO' => 'protocolo',
        'Interessado' => 'interessado',
        'Requerimento' => 'requerimento',
        'Proprietário da Obra' => 'proprietario',
        'ASSUNTO' => 'assunto',
        'Objeto' => 'objeto',
        'Prazo' => 'prazo',
        'CNPJ' => 'cnpj',
        'CPF' => 'cpf',
        'Decisão' => 'decisao',
        'Fundamento Legal' => 'fundamento_legal',
        'Órgão' => 'orgao',
    ];

    /**
     * @return array{
     *     campos: array<string, string>,
     *     valores_monetarios: string[],
     *     datas: string[],
     *     numero_ato: string|null,
     *     orgao: string|null,
     *     cnpj: string|null,
     * }
     */
    public function extrair(string $texto): array
    {
        return [
            'campos' => $this->extrairChaveValor($texto),
            'valores_monetarios' => $this->extrairValoresMonetarios($texto),
            'datas' => $this->extrairDatas($texto),
            'numero_ato' => $this->extrairNumeroAto($texto),
            'orgao' => $this->extrairOrgao($texto),
            'cnpj' => $this->extrairCnpj($texto),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function extrairChaveValor(string $texto): array
    {
        $campos = [];

        foreach (self::CAMPOS_ESTRUTURADOS as $label => $chave) {
            // (?!\/\/) evita que "https://..." case como campo "https"
            $padrao = '/^'.preg_quote($label, '/').'\s*:(?!\/\/)\s*(.+)$/mu';

            if (preg_match($padrao, $texto, $partes) === 1) {
                $campos[$chave] = trim($partes[1]);
            }
        }

        return $campos;
    }

    /** @return string[] */
    private function extrairValoresMonetarios(string $texto): array
    {
        preg_match_all('/R\$\s*[\d.,]+/u', $texto, $partes);

        return array_unique($partes[0]);
    }

    /** @return string[] */
    private function extrairDatas(string $texto): array
    {
        preg_match_all('/\b\d{1,2}\/\d{1,2}\/\d{4}\b/', $texto, $partes);

        return array_unique($partes[0]);
    }

    private function extrairNumeroAto(string $texto): ?string
    {
        if (preg_match('/(?:DECRETO|PORTARIA|LEI|RESOLUÇÃO)\s+[NnNº°\.]*\s*(\d+[\w\/.-]*)/u', $texto, $partes) === 1) {
            return trim($partes[1]);
        }

        return null;
    }

    private function extrairOrgao(string $texto): ?string
    {
        if (preg_match('/Secretaria\s+(?:Municipal\s+(?:de|do|da)\s+)?[\p{L}\s]+/u', $texto, $partes) === 1) {
            return trim($partes[0]);
        }

        return null;
    }

    private function extrairCnpj(string $texto): ?string
    {
        if (preg_match('/\b\d{2}\.\d{3}\.\d{3}\/\d{4}-\d{2}\b/', $texto, $partes) === 1) {
            return $partes[0];
        }

        return null;
    }
}

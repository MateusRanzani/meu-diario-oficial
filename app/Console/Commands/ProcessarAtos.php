<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ResolveOpcoesComuns;
use App\Models\Ato;
use App\Services\LlmClient;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('diario:processar
    {--ibge= : Processar apenas atos deste município}
    {--lote= : Quantidade de atos por execução (padrão: config llm.lote)}
    {--force : Re-processar atos que já foram processados}')]
#[Description('Envia atos pendentes ao LLM local para classificação e extração de campos')]
class ProcessarAtos extends Command
{
    use ResolveOpcoesComuns;

    private const SISTEMA_PROMPT = <<<'PROMPT'
        Você é um especialista em direito administrativo municipal brasileiro.
        Analise o texto de um ato publicado em Diário Oficial e responda APENAS com um objeto JSON válido, sem explicações.

        O JSON deve ter exatamente estes campos:
        {
          "tipo": string,
          "orgao": string or null,
          "resumo": string,
          "numero_ato": string or null,
          "data_ato": string or null,
          "valor": number or null,
          "objeto": string or null,
          "confianca": number
        }

        Regras:
        - "tipo": um destes valores exatos: decreto, portaria, lei, resolucao, aviso_licitacao, contrato, extrato_contrato, nomeacao, exoneracao, autorizacao_despesa, comunicado, edital, notificacao, outro
        - "orgao": secretaria ou órgão responsável pelo ato, sem o nome do município
        - "resumo": 1 a 3 frases em português descrevendo o que o ato faz, em linguagem simples
        - "numero_ato": número identificador do ato (ex: "123/2026"), null se não houver
        - "data_ato": data do ato em formato AAAA-MM-DD, null se não encontrar
        - "valor": valor monetário em reais como número decimal, null se não houver
        - "objeto": objeto do contrato/licitação resumido em até 80 caracteres, null se não aplicável
        - "confianca": número entre 0 e 1 indicando sua confiança na classificação
        PROMPT;

    public function handle(LlmClient $llm): int
    {
        $lote = (int) ($this->option('lote') ?? config('llm.lote'));
        $force = (bool) $this->option('force');

        $consulta = Ato::query()
            ->with('diario.municipio')
            ->where('status', $force ? '!=' : '=', $force ? 'pendente' : 'pendente');

        if (! $force) {
            $consulta->where('status', 'pendente');
        }

        if ($this->option('ibge') !== null) {
            $consulta->whereHas(
                'diario.municipio',
                fn ($q) => $q->where('codigo_ibge', $this->option('ibge')),
            );
        }

        $total = min($lote, $consulta->count());

        $consulta->limit($lote);

        if ($total === 0) {
            $this->info('Nenhum ato pendente encontrado.');

            return self::SUCCESS;
        }

        $this->line("Processando {$this->numero($total)} ato(s) com modelo: {$llm->modeloAtivo()}");
        $barra = $this->output->createProgressBar($total);
        $barra->start();

        $processados = 0;
        $erros = 0;

        $consulta->each(function (Ato $ato) use ($llm, $barra, &$processados, &$erros): void {
            try {
                $this->processarAto($ato, $llm);
                $processados++;
            } catch (\Throwable $e) {
                $erros++;

                try {
                    $ato->update(['status' => 'erro', 'json_bruto' => $e->getMessage()]);
                } catch (\Throwable) {
                    // Ignora falha ao registrar o erro para não interromper o lote
                }
            }

            $barra->advance();
        });

        $barra->finish();
        $this->newLine(2);
        $this->line("Processados: {$this->numero($processados)}");

        if ($erros > 0) {
            $this->warn("Erros: {$this->numero($erros)}");
        }

        return self::SUCCESS;
    }

    private function processarAto(Ato $ato, LlmClient $llm): void
    {
        $texto = $ato->texto_original;

        // Trunca textos muito longos para não estourar o contexto do modelo
        if (mb_strlen($texto) > 4000) {
            $texto = mb_substr($texto, 0, 4000).'... [texto truncado]';
        }

        $usuarioPrompt = "Analise este ato do Diário Oficial:\n\n{$texto}";

        $dados = $llm->completarJson(self::SISTEMA_PROMPT, $usuarioPrompt);

        $ato->update([
            'tipo' => $this->valorString($dados, 'tipo') ?? $ato->tipo,
            'orgao' => $this->valorString($dados, 'orgao') ?? $ato->orgao,
            'resumo' => $this->valorString($dados, 'resumo'),
            'numero_ato' => $this->valorString($dados, 'numero_ato') ?? $ato->numero_ato,
            'data_ato' => $this->valorData($dados),
            'valor' => isset($dados['valor']) && is_numeric($dados['valor']) ? (float) $dados['valor'] : null,
            'objeto' => $this->valorString($dados, 'objeto'),
            'confianca' => isset($dados['confianca']) && is_numeric($dados['confianca'])
                ? min(1.0, max(0.0, (float) $dados['confianca']))
                : null,
            'fonte_extracao' => 'llm',
            'modelo_usado' => $llm->modeloAtivo(),
            'json_bruto' => json_encode($dados, JSON_UNESCAPED_UNICODE),
            'status' => 'processado',
        ]);
    }

    /** @param array<string, mixed> $dados */
    private function valorString(array $dados, string $chave): ?string
    {
        $valor = $dados[$chave] ?? null;

        return is_string($valor) && trim($valor) !== '' ? trim($valor) : null;
    }

    /** @param array<string, mixed> $dados */
    private function valorData(array $dados): ?string
    {
        $valor = $dados['data_ato'] ?? null;

        if (! is_string($valor)) {
            return null;
        }

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $valor) === 1 ? $valor : null;
    }
}

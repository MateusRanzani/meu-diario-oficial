<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Cliente para APIs OpenAI-compatible (LM Studio, Ollama, etc.).
 *
 * Só usa o endpoint /chat/completions — não requer autenticação para
 * servidores locais.
 */
class LlmClient
{
    public function __construct(
        private readonly string $urlBase,
        private readonly string $modelo,
        private readonly float $temperatura,
        private readonly int $timeout,
        private readonly int $timeoutConexao,
    ) {}

    /**
     * Envia um prompt ao LLM e retorna o texto da resposta.
     *
     * @throws \RuntimeException quando a requisição falha ou o modelo não responde
     */
    public function completar(string $sistemaPrompt, string $usuarioPrompt): string
    {
        try {
            $resposta = Http::timeout($this->timeout)
                ->connectTimeout($this->timeoutConexao)
                ->post("{$this->urlBase}/chat/completions", [
                    'model' => $this->modelo,
                    'temperature' => $this->temperatura,
                    'messages' => [
                        ['role' => 'system', 'content' => $sistemaPrompt],
                        ['role' => 'user', 'content' => $usuarioPrompt],
                    ],
                ]);
        } catch (ConnectionException $e) {
            throw new \RuntimeException(
                'Não foi possível conectar ao LM Studio. Verifique se o servidor local está ativo em '.$this->urlBase,
                previous: $e,
            );
        }

        if ($resposta->failed()) {
            throw new \RuntimeException(
                "LLM retornou erro HTTP {$resposta->status()}: ".$resposta->body(),
            );
        }

        $texto = $resposta->json('choices.0.message.content');

        if (! is_string($texto) || trim($texto) === '') {
            throw new \RuntimeException('LLM retornou resposta vazia ou malformada: '.$resposta->body());
        }

        return trim($texto);
    }

    /**
     * Envia um prompt e espera uma resposta em JSON válido.
     *
     * Tenta extrair JSON mesmo quando o modelo inclui texto explicativo
     * antes ou depois do bloco `{ }`.
     *
     * @return array<string, mixed>
     *
     * @throws \RuntimeException quando não encontra JSON válido na resposta
     */
    public function completarJson(string $sistemaPrompt, string $usuarioPrompt): array
    {
        $texto = $this->completar($sistemaPrompt, $usuarioPrompt);

        // Tenta extrair bloco JSON mesmo com texto em volta
        // Remove bloco <think>...</think> que modelos de raciocínio (Qwen 3, etc.) emitem antes do JSON
        $texto = (string) preg_replace('/<think>[\s\S]*?<\/think>/u', '', $texto);
        $texto = trim($texto);

        if (preg_match('/\{[\s\S]*\}/u', $texto, $partes) === 1) {
            $dados = json_decode($partes[0], associative: true);

            if (is_array($dados)) {
                return $dados;
            }
        }

        throw new \RuntimeException("Resposta do LLM não contém JSON válido: {$texto}");
    }

    public function modeloAtivo(): string
    {
        return $this->modelo;
    }
}

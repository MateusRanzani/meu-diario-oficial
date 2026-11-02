<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | URL base do servidor LLM
    |--------------------------------------------------------------------------
    |
    | LM Studio sobe uma API compatível com OpenAI em localhost:1234 por padrão.
    | Qualquer servidor OpenAI-compatible funciona aqui (Ollama, etc.).
    |
    */

    'url_base' => env('LLM_URL', 'http://localhost:1234/v1'),

    /*
    |--------------------------------------------------------------------------
    | Modelo de chat
    |--------------------------------------------------------------------------
    |
    | Identificador do modelo carregado no LM Studio. Aparece no topo da
    | interface quando o servidor local está ativo.
    |
    */

    'modelo' => env('LLM_MODEL', 'local-model'),

    /*
    |--------------------------------------------------------------------------
    | Temperatura
    |--------------------------------------------------------------------------
    |
    | Controla a aleatoriedade das respostas. 0.1 é quase determinístico,
    | ideal para extração estruturada de dados.
    |
    */

    'temperatura' => (float) env('LLM_TEMPERATURA', 0.1),

    /*
    |--------------------------------------------------------------------------
    | Timeouts
    |--------------------------------------------------------------------------
    |
    | Modelos locais podem demorar mais que APIs remotas. 120s por requisição
    | é razoável para textos longos em hardware doméstico.
    |
    */

    'timeout' => (int) env('LLM_TIMEOUT', 120),

    'timeout_conexao' => (int) env('LLM_CONNECT_TIMEOUT', 10),

    /*
    |--------------------------------------------------------------------------
    | Lote de processamento
    |--------------------------------------------------------------------------
    |
    | Quantos atos processar por execução do comando diario:processar.
    | Mantenha baixo durante testes para não travar o computador.
    |
    */

    'lote' => (int) env('LLM_LOTE', 20),

];

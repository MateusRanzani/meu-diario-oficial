<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Endereço da API
    |--------------------------------------------------------------------------
    |
    | A documentação antiga cita "api.queridodiario.ok.org.br", que está fora do
    | ar. Só a API mudou de endereço: os arquivos dos diários continuam sendo
    | servidos por "data.queridodiario.ok.org.br".
    |
    */

    'url_base' => env('QD_API_URL', 'https://api.queridodiario.org.br'),

    /*
    |--------------------------------------------------------------------------
    | Município alvo
    |--------------------------------------------------------------------------
    |
    | Código IBGE usado quando o comando não recebe "--ibge". Descubra o código
    | de um município com "php artisan diario:municipio {nome}".
    |
    */

    'codigo_ibge' => env('QD_TERRITORY_ID'),

    /*
    |--------------------------------------------------------------------------
    | Limite de requisições
    |--------------------------------------------------------------------------
    |
    | A API não bloqueia, mas a referência publicada é de 60 requisições por
    | minuto. Usamos metade disso por folga e por educação com um serviço
    | público e gratuito.
    |
    */

    'requisicoes_por_minuto' => (int) env('QD_REQUESTS_PER_MINUTE', 30),

    'timeout' => (int) env('QD_TIMEOUT', 60),

    'timeout_conexao' => (int) env('QD_CONNECT_TIMEOUT', 10),

    /*
    |--------------------------------------------------------------------------
    | Download dos textos
    |--------------------------------------------------------------------------
    |
    | Os arquivos ficam em data.queridodiario.ok.org.br, que é outro servidor,
    | de conteúdo estático. Não vale o limite da API, mas uma pausa curta entre
    | downloads evita martelar um serviço público de graça. Baixar um mês de
    | Campinas com 500 ms de pausa custa uns 15 segundos a mais, e só.
    |
    */

    'timeout_download' => (int) env('QD_DOWNLOAD_TIMEOUT', 180),

    'pausa_download_ms' => (int) env('QD_DOWNLOAD_PAUSE_MS', 500),

    /*
    |--------------------------------------------------------------------------
    | Cache das respostas
    |--------------------------------------------------------------------------
    |
    | Evita repetir a mesma consulta durante o desenvolvimento, quando é comum
    | rodar o mesmo comando várias vezes seguidas. Use 0 para desligar.
    |
    */

    'cache_minutos' => (int) env('QD_CACHE_MINUTES', 60),

];

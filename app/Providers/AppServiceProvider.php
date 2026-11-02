<?php

namespace App\Providers;

use App\Services\LlmClient;
use App\Services\QueridoDiarioClient;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(LlmClient::class, fn (): LlmClient => new LlmClient(
            urlBase: (string) config('llm.url_base'),
            modelo: (string) config('llm.modelo'),
            temperatura: (float) config('llm.temperatura'),
            timeout: (int) config('llm.timeout'),
            timeoutConexao: (int) config('llm.timeout_conexao'),
        ));

        $this->app->singleton(QueridoDiarioClient::class, fn (): QueridoDiarioClient => new QueridoDiarioClient(
            urlBase: (string) config('querido_diario.url_base'),
            requisicoesPorMinuto: (int) config('querido_diario.requisicoes_por_minuto'),
            timeout: (int) config('querido_diario.timeout'),
            timeoutConexao: (int) config('querido_diario.timeout_conexao'),
            cacheMinutos: (int) config('querido_diario.cache_minutos'),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}

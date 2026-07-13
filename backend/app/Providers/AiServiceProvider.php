<?php

namespace App\Providers;

use App\Services\Ai\Contracts\AiClient;
use App\Services\Ai\Contracts\EmbeddingClient;
use App\Services\Ai\FakeAiClient;
use App\Services\Ai\FakeEmbeddingClient;
use App\Services\Ai\MistralClient;
use App\Services\Ai\MistralEmbeddingClient;
use Illuminate\Support\ServiceProvider;

class AiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(FakeAiClient::class);

        $this->app->singleton(AiClient::class, fn ($app): AiClient => config('fbde.ai.driver') === 'mistral'
            ? new MistralClient
            : $app->make(FakeAiClient::class));

        $this->app->singleton(FakeEmbeddingClient::class);

        $this->app->singleton(EmbeddingClient::class, fn ($app): EmbeddingClient => config('fbde.ai.driver') === 'mistral'
            ? new MistralEmbeddingClient
            : $app->make(FakeEmbeddingClient::class));
    }
}

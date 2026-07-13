<?php

namespace App\Services\Ai;

use App\Services\Ai\Contracts\AiClient;
use Illuminate\Support\Facades\Http;

/**
 * Appel Mistral (chat completions) — HTTP JSON structuré, sans LangChain.
 * Substituable : toute la logique métier ne dépend que de AiClient.
 */
class MistralClient implements AiClient
{
    public function chat(array $messages, array $options = []): string
    {
        $content = Http::withToken((string) config('services.mistral.key'))
            ->timeout(30)
            ->post((string) config('fbde.ai.endpoint'), [
                'model' => config('fbde.ai.model'),
                'messages' => $messages,
                'temperature' => $options['temperature'] ?? 0.3,
                'max_tokens' => $options['max_tokens'] ?? 400,
            ])
            ->throw()
            ->json('choices.0.message.content');

        return trim((string) $content);
    }
}

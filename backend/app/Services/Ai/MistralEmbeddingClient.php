<?php

namespace App\Services\Ai;

use App\Services\Ai\Contracts\EmbeddingClient;
use Illuminate\Support\Facades\Http;

/** Embeddings Mistral (mistral-embed) — HTTP JSON, sans dépendance externe. */
class MistralEmbeddingClient implements EmbeddingClient
{
    public function embed(string $text): array
    {
        return Http::withToken((string) config('services.mistral.key'))
            ->timeout(30)
            ->post((string) config('fbde.ai.embedding_endpoint'), [
                'model' => config('fbde.ai.embedding_model'),
                'input' => [$text],
            ])
            ->throw()
            ->json('data.0.embedding');
    }
}

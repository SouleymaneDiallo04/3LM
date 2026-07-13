<?php

namespace App\Services\Ai;

use App\Services\Ai\Contracts\EmbeddingClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

/** Embeddings Mistral (mistral-embed) — HTTP JSON, sans dépendance externe. */
class MistralEmbeddingClient implements EmbeddingClient
{
    public function embed(string $text): array
    {
        return Http::withToken((string) config('services.mistral.key'))
            ->timeout(30)
            // Rejoue sur limitation de débit (429) et erreurs serveur transitoires
            // — le palier gratuit Mistral plafonne le débit lors des backfills.
            ->retry(3, 500, fn ($e) => $e instanceof ConnectionException
                || ($e instanceof RequestException && in_array($e->response?->status(), [429, 500, 502, 503, 504], true)), throw: false)
            ->post((string) config('fbde.ai.embedding_endpoint'), [
                'model' => config('fbde.ai.embedding_model'),
                'input' => [$text],
            ])
            ->throw()
            ->json('data.0.embedding');
    }
}

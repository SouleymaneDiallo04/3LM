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
        return $this->embedMany([$text])[0];
    }

    public function embedMany(array $texts): array
    {
        $data = Http::withToken((string) config('services.mistral.key'))
            // Lots plus volumineux qu'un texte seul : on laisse plus de marge.
            ->timeout(60)
            // Rejoue sur limitation de débit (429) et erreurs serveur transitoires
            // — le palier gratuit Mistral plafonne le débit lors des backfills.
            ->retry(3, 500, fn ($e) => $e instanceof ConnectionException
                || ($e instanceof RequestException && in_array($e->response?->status(), [429, 500, 502, 503, 504], true)), throw: false)
            ->post((string) config('fbde.ai.embedding_endpoint'), [
                'model' => config('fbde.ai.embedding_model'),
                'input' => array_values($texts),
            ])
            ->throw()
            ->json('data');

        // L'API renvoie un « index » par vecteur : on s'aligne dessus plutôt que
        // de faire confiance à l'ordre de la réponse.
        usort($data, fn (array $a, array $b) => $a['index'] <=> $b['index']);

        return array_map(fn (array $d) => $d['embedding'], $data);
    }
}

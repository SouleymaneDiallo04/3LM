<?php

use App\Services\Ai\MistralEmbeddingClient;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

it('appelle l\'API embeddings et renvoie le vecteur', function (): void {
    config(['services.mistral.key' => 'k-test', 'fbde.ai.embedding_endpoint' => 'https://api.mistral.ai/v1/embeddings', 'fbde.ai.embedding_model' => 'mistral-embed']);
    Http::fake(['api.mistral.ai/*' => Http::response(['data' => [['embedding' => [0.1, 0.2, 0.3]]]])]);

    $vec = (new MistralEmbeddingClient)->embed('boulangerie');

    expect($vec)->toBe([0.1, 0.2, 0.3]);
    Http::assertSent(fn ($r) => $r->hasHeader('Authorization', 'Bearer k-test')
        && $r['model'] === 'mistral-embed'
        && $r['input'] === ['boulangerie']);
});

it('lève sur échec HTTP', function (): void {
    config(['services.mistral.key' => 'k-test']);
    Http::fake(['api.mistral.ai/*' => Http::response('nope', 500)]);
    expect(fn () => (new MistralEmbeddingClient)->embed('x'))->toThrow(RequestException::class);
});

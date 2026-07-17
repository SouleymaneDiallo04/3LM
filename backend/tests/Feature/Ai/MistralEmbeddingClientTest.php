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

it('embedde plusieurs textes en un seul appel (batch)', function (): void {
    config(['services.mistral.key' => 'k-test', 'fbde.ai.embedding_endpoint' => 'https://api.mistral.ai/v1/embeddings', 'fbde.ai.embedding_model' => 'mistral-embed']);
    Http::fake(['api.mistral.ai/*' => Http::response(['data' => [
        ['index' => 0, 'embedding' => [0.1, 0.2]],
        ['index' => 1, 'embedding' => [0.3, 0.4]],
    ]])]);

    $vecs = (new MistralEmbeddingClient)->embedMany(['boulangerie', 'garage']);

    expect($vecs)->toBe([[0.1, 0.2], [0.3, 0.4]]);
    // Un SEUL appel HTTP pour les deux textes.
    Http::assertSentCount(1);
    Http::assertSent(fn ($r) => $r['input'] === ['boulangerie', 'garage'] && $r['model'] === 'mistral-embed');
});

it('remet les vecteurs dans l\'ordre des textes même si l\'API les renvoie mélangés', function (): void {
    config(['services.mistral.key' => 'k-test']);
    // L'API renvoie index 1 avant index 0 : l'ordre doit suivre les textes envoyés.
    Http::fake(['api.mistral.ai/*' => Http::response(['data' => [
        ['index' => 1, 'embedding' => [0.9]],
        ['index' => 0, 'embedding' => [0.1]],
    ]])]);

    expect((new MistralEmbeddingClient)->embedMany(['premier', 'second']))->toBe([[0.1], [0.9]]);
});

it('rejoue sur une limitation de débit (429) puis réussit', function (): void {
    config(['services.mistral.key' => 'k-test']);
    // 429 (rate limit) puis 200 : le rejeu doit aboutir au vecteur.
    Http::fake(['api.mistral.ai/*' => Http::sequence()
        ->push('rate limited', 429)
        ->push(['data' => [['embedding' => [0.5, 0.6]]]], 200)]);

    expect((new MistralEmbeddingClient)->embed('x'))->toBe([0.5, 0.6]);
});

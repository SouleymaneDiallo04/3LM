<?php

use App\Services\Ai\Contracts\EmbeddingClient;
use App\Services\Ai\FakeEmbeddingClient;

it('lie la doublure d\'embedding en environnement de test', function (): void {
    expect(app(EmbeddingClient::class))->toBeInstanceOf(FakeEmbeddingClient::class);
});

it('produit un vecteur déterministe de 1024 flottants normalisés', function (): void {
    $client = new FakeEmbeddingClient;
    $a = $client->embed('boulangerie bordeaux');
    $b = $client->embed('boulangerie bordeaux');
    $c = $client->embed('garage lyon');

    expect($a)->toHaveCount(1024)
        ->and($a)->toBe($b)          // déterministe
        ->and($a)->not->toBe($c);    // dépend du texte

    $norm = sqrt(array_sum(array_map(fn ($x) => $x * $x, $a)));
    expect($norm)->toBeGreaterThan(0.99)->toBeLessThan(1.01); // normalisé
});

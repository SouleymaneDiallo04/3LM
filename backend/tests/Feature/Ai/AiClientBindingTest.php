<?php

use App\Services\Ai\Contracts\AiClient;
use App\Services\Ai\FakeAiClient;

it('résout AiClient sur la doublure en environnement de test', function (): void {
    expect(app(AiClient::class))->toBeInstanceOf(FakeAiClient::class)
        ->and(app(AiClient::class))->toBe(app(FakeAiClient::class)); // même singleton
});

it('la doublure renvoie la réponse configurée et enregistre les appels', function (): void {
    $fake = app(FakeAiClient::class);
    $fake->response = 'Réponse pilotée.';

    $out = app(AiClient::class)->chat([['role' => 'user', 'content' => 'x']], ['max_tokens' => 10]);

    expect($out)->toBe('Réponse pilotée.')
        ->and($fake->calls)->toHaveCount(1)
        ->and($fake->calls[0]['messages'][0]['content'])->toBe('x')
        ->and($fake->calls[0]['options']['max_tokens'])->toBe(10);
});

<?php

// backend/tests/Feature/Ai/MistralClientTest.php
use App\Services\Ai\MistralClient;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

it('appelle l\'API Mistral avec Bearer, modèle et messages, renvoie le contenu', function (): void {
    config(['services.mistral.key' => 'sk-test', 'fbde.ai.model' => 'mistral-small-latest']);
    Http::fake([
        'api.mistral.ai/*' => Http::response([
            'choices' => [['message' => ['content' => '  Résumé généré.  ']]],
        ]),
    ]);

    $out = (new MistralClient)->chat(
        [['role' => 'user', 'content' => 'Bonjour']],
        ['max_tokens' => 200],
    );

    expect($out)->toBe('Résumé généré.'); // trim appliqué

    Http::assertSent(function ($request): bool {
        return $request->hasHeader('Authorization', 'Bearer sk-test')
            && $request['model'] === 'mistral-small-latest'
            && $request['messages'][0]['content'] === 'Bonjour'
            && $request['max_tokens'] === 200;
    });
});

it('lève sur échec API', function (): void {
    config(['services.mistral.key' => 'sk-test']);
    Http::fake(['api.mistral.ai/*' => Http::response('', 500)]);

    (new MistralClient)->chat([['role' => 'user', 'content' => 'x']]);
})->throws(RequestException::class);

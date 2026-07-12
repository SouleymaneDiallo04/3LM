<?php

namespace App\Services\Ai;

use App\Services\Ai\Contracts\AiClient;

/** Doublure déterministe (tests/offline) : aucune requête réseau. */
class FakeAiClient implements AiClient
{
    public string $response = 'Résumé fictif de test.';

    /** @var list<array{messages: array, options: array}> */
    public array $calls = [];

    public function chat(array $messages, array $options = []): string
    {
        $this->calls[] = ['messages' => $messages, 'options' => $options];

        return $this->response;
    }
}

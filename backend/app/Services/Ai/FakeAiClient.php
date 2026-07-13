<?php

namespace App\Services\Ai;

use App\Services\Ai\Contracts\AiClient;

/** Doublure déterministe (tests/offline) : aucune requête réseau. */
class FakeAiClient implements AiClient
{
    // Défaut réaliste pour le mode hors-ligne (les tests fixent leur propre réponse).
    public string $response = "Boulangerie artisanale implantée à Bordeaux, active dans un secteur de proximité à forte fréquentation. L'entreprise dispose d'une vitrine en ligne et d'une présence sur les réseaux sociaux, signe d'une démarche commerciale déjà engagée. Un premier contact par e-mail axé sur la mise en avant d'une offre locale constitue une approche pertinente.";

    /** @var list<array{messages: array, options: array}> */
    public array $calls = [];

    public function chat(array $messages, array $options = []): string
    {
        $this->calls[] = ['messages' => $messages, 'options' => $options];

        return $this->response;
    }
}

<?php

namespace App\Services\Ai\Contracts;

interface EmbeddingClient
{
    /** @return list<float> vecteur de dimension fixe (1024 pour mistral-embed) */
    public function embed(string $text): array;
}

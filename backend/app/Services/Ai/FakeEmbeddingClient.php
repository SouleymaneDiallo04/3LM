<?php

namespace App\Services\Ai;

use App\Services\Ai\Contracts\EmbeddingClient;

/** Doublure déterministe (tests/offline) : vecteur unitaire dérivé du texte. */
class FakeEmbeddingClient implements EmbeddingClient
{
    public function embedMany(array $texts): array
    {
        return array_map(fn (string $t) => $this->embed($t), array_values($texts));
    }

    public function embed(string $text): array
    {
        mt_srand(crc32($text));
        $v = [];
        for ($i = 0; $i < 1024; $i++) {
            $v[] = mt_rand(-1000, 1000) / 1000;
        }
        mt_srand();
        $norm = sqrt(array_sum(array_map(fn ($x) => $x * $x, $v))) ?: 1.0;

        return array_map(fn ($x) => $x / $norm, $v);
    }
}

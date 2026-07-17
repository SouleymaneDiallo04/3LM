<?php

namespace App\Services\Ai\Contracts;

interface EmbeddingClient
{
    /** @return list<float> vecteur de dimension fixe (1024 pour mistral-embed) */
    public function embed(string $text): array;

    /**
     * Embedde un lot de textes en un seul appel — indispensable aux backfills
     * de masse (1 appel/fiche = des heures ; 100 textes/appel = des minutes).
     *
     * @param  list<string>  $texts
     * @return list<list<float>> un vecteur par texte, dans le même ordre
     */
    public function embedMany(array $texts): array;
}

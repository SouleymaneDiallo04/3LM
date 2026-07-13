<?php

namespace App\Services\Ai;

use App\Models\Establishment;
use App\Services\Ai\Contracts\EmbeddingClient;
use Illuminate\Support\Facades\DB;

class CompanyEmbedder
{
    public function __construct(
        private readonly EmbeddingClient $client,
        private readonly Prompts $prompts,
        private readonly ProspectGuard $guard,
    ) {}

    /** Génère l'embedding (garde RGPD) et le stocke en base. */
    public function embed(Establishment $establishment): void
    {
        $this->guard->assertAllowed($establishment);

        $vector = $this->client->embed($this->prompts->embeddingText($establishment));
        $literal = '['.implode(',', $vector).']';

        DB::update(
            'UPDATE establishments SET embedding = ?::vector, embedded_at = now() WHERE id = ?',
            [$literal, $establishment->id],
        );
    }
}

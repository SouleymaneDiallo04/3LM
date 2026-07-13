<?php

namespace App\Services\Ai;

use App\Models\Establishment;
use App\Services\Ai\Contracts\AiClient;

class CompanySummarizer
{
    public function __construct(
        private readonly AiClient $ai,
        private readonly Prompts $prompts,
        private readonly ProspectGuard $guard,
    ) {}

    /** Génère (sans stocker) le résumé ; lève AiGenerationDenied si interdit. */
    public function summarize(Establishment $establishment): string
    {
        $this->assertAllowed($establishment);

        return $this->ai->chat(
            $this->prompts->summaryMessages($establishment),
            ['max_tokens' => 300],
        );
    }

    /** Garde RGPD (déléguée à ProspectGuard, partagée avec l'embedding). */
    public function assertAllowed(Establishment $establishment): void
    {
        $this->guard->assertAllowed($establishment);
    }
}

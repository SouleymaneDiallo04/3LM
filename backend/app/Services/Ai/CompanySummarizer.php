<?php

namespace App\Services\Ai;

use App\Models\Establishment;
use App\Services\Ai\Contracts\AiClient;
use App\Services\Ai\Exceptions\AiGenerationDenied;
use Illuminate\Support\Facades\DB;

class CompanySummarizer
{
    public function __construct(
        private readonly AiClient $ai,
        private readonly Prompts $prompts,
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

    /** Garde RGPD : refus si non-diffusible ou présent en liste d'exclusion. */
    public function assertAllowed(Establishment $establishment): void
    {
        // Le statut de diffusion SIRENE est porté par l'unité légale (companies).
        if ($establishment->company?->is_diffusible === false) {
            throw new AiGenerationDenied('Fiche non-diffusible : génération IA non autorisée.');
        }

        $siren = $establishment->company?->siren;
        $excluded = DB::table('exclusion_list')
            ->where(fn ($q) => $q->where('identifier_type', 'siret')
                ->where('identifier_value', $establishment->siret))
            ->when($siren, fn ($q) => $q->orWhere(fn ($s) => $s
                ->where('identifier_type', 'siren')->where('identifier_value', $siren)))
            ->exists();

        if ($excluded) {
            throw new AiGenerationDenied('Fiche en liste d\'exclusion : génération IA non autorisée.');
        }
    }
}

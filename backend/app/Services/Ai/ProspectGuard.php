<?php

namespace App\Services\Ai;

use App\Models\Establishment;
use App\Services\Ai\Exceptions\AiGenerationDenied;
use Illuminate\Support\Facades\DB;

/** Garde RGPD partagée (résumé, argumentaire, embedding, similarité). */
class ProspectGuard
{
    /** Refus si la fiche est non-diffusible ou présente en liste d'exclusion. */
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

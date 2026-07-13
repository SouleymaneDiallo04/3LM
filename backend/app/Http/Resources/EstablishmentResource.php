<?php

namespace App\Http\Resources;

use App\Models\Establishment;
use App\Services\Scoring\ScoringService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Fiche établissement (EF-02, ADR 0003) : l'établissement porte
 * l'adresse et les enrichissements, l'unité légale les données du
 * registre.
 *
 * @mixin Establishment
 */
class EstablishmentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        // Cohérence RGPD avec la garde de génération : si l'unité légale est
        // devenue non-diffusible, on ne sert plus le résumé IA mémorisé. On ne
        // masque que ce cas explicite (relation chargée + is_diffusible faux),
        // sans changer le comportement des vues où la relation n'est pas chargée.
        $aiHidden = $this->relationLoaded('company') && $this->company?->is_diffusible === false;

        return [
            'id' => $this->id,
            'siret' => $this->siret,
            'name' => $this->name ?? $this->whenLoaded('company', fn () => $this->company->legal_name),
            'is_headquarters' => $this->is_headquarters,
            'status' => $this->status,
            'naf_code' => $this->naf_code,
            'employee_range' => $this->employee_range,
            'address' => [
                'line' => $this->address_line,
                'postal_code' => $this->postal_code,
                'city' => $this->city,
                'city_code' => $this->city_code,
                'department_code' => $this->department_code,
            ],
            'coordinates' => $this->when(
                isset($this->longitude, $this->latitude),
                fn (): array => [
                    'longitude' => (float) $this->longitude,
                    'latitude' => (float) $this->latitude,
                ],
            ),
            'geo_source' => $this->geo_source,
            'contact' => [
                'phone' => $this->phone,
                'website' => $this->website,
                'email' => $this->email,
            ],
            'opening_hours' => $this->opening_hours,
            'social_links' => $this->social_links,
            'contact_form_url' => $this->contact_form_url,
            'description' => $this->description,
            'technologies' => $this->technologies,
            'rating' => $this->rating !== null ? (float) $this->rating : null,
            'reviews_count' => $this->reviews_count,
            'rating_source' => $this->rating_source,
            'reputation_score' => $this->reputation_score,
            'commercial_score' => $this->commercial_score,
            'commercial_tier' => app(ScoringService::class)
                ->tier($this->commercial_score),
            'company' => $this->whenLoaded('company', fn (): array => [
                'siren' => $this->company->siren,
                'legal_name' => $this->company->legal_name,
                'legal_form' => $this->company->legal_form,
                'status' => $this->company->status,
                'employee_range' => $this->company->employee_range,
                'incorporated_at' => $this->company->incorporated_at?->toDateString(),
            ]),
            'imported_at' => $this->imported_at?->toIso8601String(),
            'enriched_at' => $this->enriched_at?->toIso8601String(),
            'crawled_at' => $this->crawled_at?->toIso8601String(),
            'ai_summary' => $aiHidden ? null : $this->ai_summary,
            'ai_summary_version' => $aiHidden ? null : $this->ai_summary_version,
            'ai_summary_at' => $aiHidden ? null : $this->ai_summary_at?->toIso8601String(),
            // Péremption : la fiche a été enrichie/crawlée après le résumé.
            'ai_summary_stale' => ! $aiHidden && $this->ai_summary_at !== null && (
                ($this->enriched_at !== null && $this->enriched_at->gt($this->ai_summary_at))
                || ($this->crawled_at !== null && $this->crawled_at->gt($this->ai_summary_at))
            ),
        ];
    }
}

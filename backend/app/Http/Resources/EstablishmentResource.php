<?php

namespace App\Http\Resources;

use App\Models\Establishment;
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
            'rating' => $this->rating !== null ? (float) $this->rating : null,
            'reviews_count' => $this->reviews_count,
            'rating_source' => $this->rating_source,
            'reputation_score' => $this->reputation_score,
            'commercial_score' => $this->commercial_score,
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
        ];
    }
}

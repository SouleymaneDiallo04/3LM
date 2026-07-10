<?php

namespace App\Services\Enrichment;

use App\Models\Establishment;
use App\Services\Enrichment\Contracts\PoiConnector;

/**
 * Rapprochement POI → établissement (phase 4) : proximité géographique
 * (≤ 75 m) ET similarité de dénomination (pg_trgm) — un POI proche mais
 * au nom étranger n'enrichit rien. Précédence par champ (correctif 15) :
 * l'enrichissement complète, il n'écrase jamais une valeur existante.
 */
class EnrichmentService
{
    private const MAX_DISTANCE_METERS = 75;

    private const MIN_NAME_SIMILARITY = 0.35;

    /**
     * @return array{matched: int, unmatched: int, updated_fields: int}
     */
    public function enrich(PoiConnector $connector, string $source): array
    {
        $stats = ['matched' => 0, 'unmatched' => 0, 'updated_fields' => 0];

        foreach ($connector->pois() as $poi) {
            $establishment = $this->match($poi);

            if ($establishment === null) {
                $stats['unmatched']++;

                continue;
            }

            $stats['matched']++;
            $stats['updated_fields'] += $this->apply($establishment, $poi, $source);
        }

        return $stats;
    }

    private function match(array $poi): ?Establishment
    {
        return Establishment::query()
            ->where('status', 'active')
            ->whereRaw(
                'ST_DWithin(location, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography, ?)',
                [$poi['longitude'], $poi['latitude'], self::MAX_DISTANCE_METERS],
            )
            ->whereRaw(
                'similarity(normalized_name, ?) >= ?',
                [$poi['normalized_name'], self::MIN_NAME_SIMILARITY],
            )
            ->orderByRaw(
                'ST_Distance(location, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography)',
                [$poi['longitude'], $poi['latitude']],
            )
            ->first();
    }

    /** Applique les champs manquants ; renvoie le nombre de champs complétés. */
    private function apply(Establishment $establishment, array $poi, string $source): int
    {
        $updates = [];

        if ($establishment->phone === null && ! empty($poi['phone'])) {
            $updates['phone'] = $poi['phone'];
        }
        if ($establishment->website === null && ! empty($poi['website'])) {
            $updates['website'] = $poi['website'];
        }
        if ($establishment->opening_hours === null && ! empty($poi['opening_hours'])) {
            $updates['opening_hours'] = ['raw' => $poi['opening_hours'], 'source' => $source];
        }

        if ($updates !== []) {
            $updates['enriched_at'] = now();
            $establishment->update($updates);
        }

        return count($updates) > 0 ? count($updates) - 1 : 0; // enriched_at non compté
    }
}

<?php

namespace App\Services\Geocoding;

use App\Models\Establishment;
use App\Services\Geocoding\Contracts\Geocoder;
use Illuminate\Support\Facades\DB;

/**
 * Géocodage des établissements sans coordonnées (correctif 5) :
 * sélection des fiches non géolocalisées disposant d'une adresse,
 * traitement par lots, seuil de score d'appariement configurable.
 * Le taux d'appariement est mesuré et rapporté à chaque exécution
 * (objectif ≥ 90 % des établissements actifs).
 */
class GeocodingService
{
    public function __construct(private readonly Geocoder $geocoder) {}

    /**
     * @param  string|null  $department  limiter à un département
     * @param  int|null  $limit  plafond de fiches traitées (tests, reprises)
     * @return array{candidates: int, geocoded: int, below_threshold: int, unmatched: int}
     */
    public function geocodeMissing(?string $department = null, ?int $limit = null): array
    {
        $stats = ['candidates' => 0, 'geocoded' => 0, 'below_threshold' => 0, 'unmatched' => 0];
        $minScore = (float) config('services.ban.min_score');
        $batchSize = (int) config('services.ban.batch_size');
        $remaining = $limit;

        Establishment::query()
            ->whereNull('location')
            ->where(function ($query): void {
                $query->whereNotNull('address_line')->orWhereNotNull('city');
            })
            ->when($department, fn ($q) => $q->where('department_code', $department))
            ->select(['id', 'siret', 'address_line', 'postal_code', 'city', 'city_code'])
            ->chunkById($batchSize, function ($establishments) use (&$stats, &$remaining, $minScore): bool {
                if ($remaining !== null) {
                    $establishments = $establishments->take($remaining);
                }

                $addresses = $establishments->map(static fn (Establishment $e): array => [
                    'id' => $e->siret,
                    'address' => $e->address_line,
                    'postcode' => $e->postal_code,
                    'city' => $e->city,
                    'city_code' => $e->city_code,
                ])->values()->all();

                $stats['candidates'] += count($addresses);
                $results = $this->geocoder->geocodeBatch($addresses);

                $accepted = [];

                foreach ($addresses as $address) {
                    $result = $results[$address['id']] ?? null;

                    if ($result === null) {
                        $stats['unmatched']++;
                    } elseif ($result['score'] < $minScore) {
                        $stats['below_threshold']++;
                    } else {
                        $accepted[] = ['siret' => $address['id'], ...$result];
                    }
                }

                $stats['geocoded'] += $this->applyResults($accepted);

                if ($remaining !== null) {
                    $remaining -= count($addresses);

                    if ($remaining <= 0) {
                        return false; // stoppe le chunking
                    }
                }

                return true;
            });

        return $stats;
    }

    /**
     * @param  list<array{siret: string, longitude: float, latitude: float, score: float}>  $results
     */
    private function applyResults(array $results): int
    {
        if ($results === []) {
            return 0;
        }

        $values = [];
        $bindings = [];

        foreach ($results as $row) {
            $values[] = '(?, ?::float, ?::float, ?::numeric)';
            array_push($bindings, $row['siret'], $row['longitude'], $row['latitude'], $row['score']);
        }

        return DB::update(
            'UPDATE establishments AS e
             SET location = ST_SetSRID(ST_MakePoint(d.lon, d.lat), 4326)::geography,
                 geo_source = \'ban\',
                 geo_score = d.score
             FROM (VALUES '.implode(', ', $values).') AS d(siret, lon, lat, score)
             WHERE e.siret = d.siret',
            $bindings,
        );
    }
}

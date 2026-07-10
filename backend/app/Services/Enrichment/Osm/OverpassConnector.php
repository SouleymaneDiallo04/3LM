<?php

namespace App\Services\Enrichment\Osm;

use App\Services\Enrichment\Contracts\PoiConnector;
use App\Services\Ingestion\NameNormalizer;
use Illuminate\Support\Facades\Http;

/**
 * POI OpenStreetMap via l'API Overpass (directive client : open data,
 * pas de scraping Google). Interroge la zone administrative du
 * département (ref:INSEE) pour les éléments nommés portant au moins un
 * tag de contact ; les ways sont ramenés à leur centre (out center).
 */
class OverpassConnector implements PoiConnector
{
    public function __construct(
        private readonly NameNormalizer $normalizer,
        private readonly string $department,
    ) {}

    public function pois(): iterable
    {
        $query = sprintf(<<<'OVERPASS'
            [out:json][timeout:900];
            area["boundary"="administrative"]["admin_level"="6"]["ref:INSEE"="%s"]->.dep;
            (
              nwr["name"]["phone"](area.dep);
              nwr["name"]["contact:phone"](area.dep);
              nwr["name"]["website"](area.dep);
              nwr["name"]["contact:website"](area.dep);
              nwr["name"]["opening_hours"](area.dep);
            );
            out center tags;
            OVERPASS, $this->department);

        // L'instance publique Overpass exige un client identifié (406 sinon).
        $elements = Http::timeout(900)
            ->withHeaders(['User-Agent' => 'FBDEBot/1.0 (enrichissement B2B ; respecte la politique Overpass)'])
            ->asForm()
            ->post(config('fbde.overpass_api'), ['data' => $query])
            ->throw()
            ->json('elements') ?? [];

        foreach ($elements as $element) {
            $tags = $element['tags'] ?? [];
            $name = $tags['name'] ?? null;
            $latitude = $element['lat'] ?? $element['center']['lat'] ?? null;
            $longitude = $element['lon'] ?? $element['center']['lon'] ?? null;

            if ($name === null || $latitude === null || $longitude === null) {
                continue;
            }

            yield [
                'name' => $name,
                'normalized_name' => $this->normalizer->normalize($name) ?? mb_strtolower($name),
                'latitude' => (float) $latitude,
                'longitude' => (float) $longitude,
                'phone' => $tags['phone'] ?? $tags['contact:phone'] ?? null,
                'website' => $this->normalizeUrl($tags['website'] ?? $tags['contact:website'] ?? null),
                'opening_hours' => $tags['opening_hours'] ?? null,
            ];
        }
    }

    /** Les tags OSM omettent souvent le schéma — l'URL stockée est complète. */
    private function normalizeUrl(?string $url): ?string
    {
        if ($url === null || trim($url) === '') {
            return null;
        }

        $url = trim($url);

        return preg_match('#^https?://#i', $url) === 1 ? $url : 'https://'.$url;
    }
}

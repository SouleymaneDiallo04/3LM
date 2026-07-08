<?php

namespace App\Services\Geocoding;

use App\Services\Geocoding\Contracts\Geocoder;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Géocodeur BAN — Base Adresse Nationale (api-adresse.data.gouv.fr),
 * géocodeur officiel français, gratuit, avec traitement par lot CSV
 * (correctif 5 transposé France). Le code commune INSEE est transmis
 * pour fiabiliser l'appariement.
 */
class BanGeocoder implements Geocoder
{
    public function geocodeBatch(array $addresses): array
    {
        if ($addresses === []) {
            return [];
        }

        $response = Http::timeout(120)
            ->attach('data', $this->buildCsv($addresses), 'addresses.csv')
            ->post(rtrim(config('services.ban.url'), '/').'/search/csv/', [
                ['name' => 'columns', 'contents' => 'address'],
                ['name' => 'columns', 'contents' => 'postcode'],
                ['name' => 'columns', 'contents' => 'city'],
                ['name' => 'citycode', 'contents' => 'city_code'],
            ]);

        if ($response->failed()) {
            throw new RuntimeException(
                'Géocodage BAN en échec : HTTP '.$response->status(),
            );
        }

        return $this->parseResults($response->body());
    }

    /** @param list<array{id: string, address: ?string, postcode: ?string, city: ?string, city_code: ?string}> $addresses */
    private function buildCsv(array $addresses): string
    {
        $stream = fopen('php://temp', 'r+');
        fputcsv($stream, ['id', 'address', 'postcode', 'city', 'city_code'], escape: '');

        foreach ($addresses as $row) {
            fputcsv($stream, [
                $row['id'],
                $row['address'] ?? '',
                $row['postcode'] ?? '',
                $row['city'] ?? '',
                $row['city_code'] ?? '',
            ], escape: '');
        }

        rewind($stream);
        $csv = (string) stream_get_contents($stream);
        fclose($stream);

        return $csv;
    }

    /** @return array<string, array{longitude: float, latitude: float, score: float}> */
    private function parseResults(string $body): array
    {
        $lines = preg_split('/\r\n|\n|\r/', trim($body));

        if ($lines === false || count($lines) < 2) {
            return [];
        }

        $headers = str_getcsv(array_shift($lines), escape: '');
        $results = [];

        foreach ($lines as $line) {
            $values = str_getcsv($line, escape: '');

            if (count($values) !== count($headers)) {
                continue;
            }

            $row = array_combine($headers, $values);

            if (($row['longitude'] ?? '') === '' || ($row['latitude'] ?? '') === '') {
                continue; // adresse non appariée
            }

            $results[$row['id']] = [
                'longitude' => (float) $row['longitude'],
                'latitude' => (float) $row['latitude'],
                'score' => (float) ($row['result_score'] ?? 0),
            ];
        }

        return $results;
    }
}

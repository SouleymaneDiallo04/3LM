<?php

namespace App\Services\Ingestion;

use App\Models\Company;
use App\Models\Establishment;
use App\Services\Ingestion\Contracts\CompanyRegistryConnector;
use Illuminate\Support\Facades\DB;

/**
 * Pipeline d'ingestion (§5.1, EF-04) — principes du CDC appliqués :
 * - la déduplication est traitée À L'INGESTION : upsert sur identifiant
 *   fort (SIREN/SIRET), jamais de doublon (EF-04.1) ;
 * - lots de 1 000 lignes (§9.2) ;
 * - rapport par import : créés / mis à jour / rejetés (EF-04.5) ;
 * - liste d'exclusion RGPD respectée (§2.4) ;
 * - précédence par champ (correctif 15) : SIRENE ne met à jour que les
 *   champs dont il est la source — les enrichissements (téléphone, email,
 *   réseaux, scores…) ne sont jamais écrasés par un réimport.
 */
class IngestionService
{
    private const BATCH_SIZE = 1000;

    /** Colonnes companies mises à jour par SIRENE lors d'un réimport. */
    private const COMPANY_UPDATE_COLUMNS = [
        'legal_name', 'normalized_name', 'legal_form', 'status', 'naf_code',
        'employee_range', 'incorporated_at', 'is_diffusible', 'imported_at',
        'updated_at',
    ];

    /** Colonnes establishments mises à jour par SIRENE lors d'un réimport. */
    private const ESTABLISHMENT_UPDATE_COLUMNS = [
        'name', 'normalized_name', 'is_headquarters', 'status', 'naf_code',
        'employee_range', 'address_line', 'postal_code', 'city', 'city_code',
        'department_code', 'imported_at', 'updated_at',
    ];

    /**
     * Importe les unités légales du connecteur.
     *
     * @return array{created: int, updated: int, rejected: int}
     */
    public function importCompanies(CompanyRegistryConnector $connector): array
    {
        $stats = ['created' => 0, 'updated' => 0, 'rejected' => 0];
        $excluded = $this->exclusionSet('siren');
        $now = now();

        foreach ($this->batches($connector->companies()) as $batch) {
            $rows = [];

            foreach ($batch as $row) {
                if (isset($excluded[$row['siren']])) {
                    $stats['rejected']++;

                    continue;
                }

                $row['imported_at'] = $now;
                $row['created_at'] = $now;
                $row['updated_at'] = $now;
                $rows[] = $row;
            }

            if ($rows === []) {
                continue;
            }

            $existing = Company::query()
                ->whereIn('siren', array_column($rows, 'siren'))
                ->count();

            Company::upsert($rows, ['siren'], self::COMPANY_UPDATE_COLUMNS);

            $stats['updated'] += $existing;
            $stats['created'] += count($rows) - $existing;
        }

        return $stats;
    }

    /**
     * Importe les établissements du connecteur. Les unités légales doivent
     * avoir été importées au préalable (rattachement par SIREN).
     *
     * @return array{created: int, updated: int, rejected: int, geocoded: int}
     */
    public function importEstablishments(CompanyRegistryConnector $connector): array
    {
        $stats = ['created' => 0, 'updated' => 0, 'rejected' => 0, 'geocoded' => 0];
        $excludedSirets = $this->exclusionSet('siret');
        $excludedSirens = $this->exclusionSet('siren');
        $now = now();

        foreach ($this->batches($connector->establishments()) as $batch) {
            $rows = [];
            $coordinates = [];

            // Rattachement SIREN → company_id en une requête par lot.
            $companyIds = Company::query()
                ->whereIn('siren', array_unique(array_column($batch, 'siren')))
                ->pluck('id', 'siren');

            foreach ($batch as $row) {
                if (isset($excludedSirets[$row['siret']])
                    || isset($excludedSirens[$row['siren']])
                    || ! isset($companyIds[$row['siren']])) {
                    $stats['rejected']++;

                    continue;
                }

                if ($row['longitude'] !== null && $row['latitude'] !== null) {
                    $coordinates[] = [
                        'siret' => $row['siret'],
                        'longitude' => $row['longitude'],
                        'latitude' => $row['latitude'],
                    ];
                }

                $row['company_id'] = $companyIds[$row['siren']];
                $row['imported_at'] = $now;
                $row['created_at'] = $now;
                $row['updated_at'] = $now;
                unset($row['siren'], $row['longitude'], $row['latitude']);
                $rows[] = $row;
            }

            if ($rows === []) {
                continue;
            }

            $existing = Establishment::query()
                ->whereIn('siret', array_column($rows, 'siret'))
                ->count();

            Establishment::upsert($rows, ['siret'], self::ESTABLISHMENT_UPDATE_COLUMNS);

            $stats['updated'] += $existing;
            $stats['created'] += count($rows) - $existing;
            $stats['geocoded'] += $this->applyCoordinates($coordinates);
        }

        return $stats;
    }

    /**
     * Applique les coordonnées SIRENE géolocalisées par lot (ST_MakePoint).
     *
     * @param  list<array{siret: string, longitude: float, latitude: float}>  $coordinates
     */
    private function applyCoordinates(array $coordinates): int
    {
        if ($coordinates === []) {
            return 0;
        }

        $values = [];
        $bindings = [];

        foreach ($coordinates as $point) {
            $values[] = '(?, ?::float, ?::float)';
            array_push($bindings, $point['siret'], $point['longitude'], $point['latitude']);
        }

        return DB::update(
            'UPDATE establishments AS e
             SET location = ST_SetSRID(ST_MakePoint(d.lon, d.lat), 4326)::geography,
                 geo_source = \'sirene\'
             FROM (VALUES '.implode(', ', $values).') AS d(siret, lon, lat)
             WHERE e.siret = d.siret',
            $bindings,
        );
    }

    /**
     * Regroupe un flux de lignes en lots de 1 000 (§9.2).
     *
     * @param  iterable<int, array<string, mixed>>  $rows
     * @return iterable<int, list<array<string, mixed>>>
     */
    private function batches(iterable $rows): iterable
    {
        $batch = [];

        foreach ($rows as $row) {
            $batch[] = $row;

            if (count($batch) >= self::BATCH_SIZE) {
                yield $batch;
                $batch = [];
            }
        }

        if ($batch !== []) {
            yield $batch;
        }
    }

    /**
     * Identifiants exclus de la collecte (RGPD, §2.4).
     *
     * @return array<string, true>
     */
    private function exclusionSet(string $type): array
    {
        return DB::table('exclusion_list')
            ->where('identifier_type', $type)
            ->pluck('identifier_value')
            ->flip()
            ->map(static fn (): bool => true)
            ->all();
    }
}

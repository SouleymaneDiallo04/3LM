<?php

namespace App\Services\Ingestion\Sirene;

use App\Services\Ingestion\Contracts\CompanyRegistryConnector;
use App\Services\Ingestion\NameNormalizer;
use Generator;
use RuntimeException;

/**
 * Connecteur SIRENE — fichiers stock INSEE (open data) :
 * StockUniteLegale et StockEtablissement (variante géolocalisée acceptée).
 *
 * Lecture en flux (générateurs) : le stock national (~15 M de lignes)
 * n'est jamais chargé en mémoire. Le statut de diffusion est respecté dès
 * la lecture : seules les unités « O » sont pleinement diffusées ; les
 * unités « P » (protégées) sont marquées is_diffusible = false et leurs
 * champs masqués par l'INSEE restent vides (note de cadrage §3).
 */
class SireneStockConnector implements CompanyRegistryConnector
{
    /** @var array<string, true>|null Restriction aux SIREN listés (import par département). */
    private ?array $sirenWhitelist = null;

    public function __construct(
        private readonly NameNormalizer $normalizer,
        private readonly string $unitesLegalesPath,
        private readonly string $etablissementsPath,
        private readonly ?string $departmentFilter = null,
    ) {}

    /**
     * Restreint companies() aux SIREN donnés. Utilisé pour l'import par
     * département : le fichier des unités légales est national, seules
     * celles ayant un établissement dans le périmètre sont importées.
     *
     * @param  list<string>  $sirens
     */
    public function setSirenWhitelist(array $sirens): void
    {
        $this->sirenWhitelist = array_fill_keys($sirens, true);
    }

    /**
     * Pré-scan : SIREN de tous les établissements du périmètre courant
     * (une passe en flux sur le fichier des établissements).
     *
     * @return list<string>
     */
    public function collectSirens(): array
    {
        $sirens = [];

        foreach ($this->establishments() as $row) {
            $sirens[$row['siren']] = true;
        }

        return array_keys($sirens);
    }

    public function companies(): iterable
    {
        foreach ($this->readCsv($this->unitesLegalesPath) as $row) {
            if ($this->sirenWhitelist !== null
                && ! isset($this->sirenWhitelist[$row['siren'] ?? ''])) {
                continue;
            }

            $name = $row['denominationUniteLegale']
                ?: trim(($row['nomUniteLegale'] ?? '').' '.($row['prenom1UniteLegale'] ?? ''));

            if ($name === '' || $row['siren'] === '') {
                continue; // ligne inexploitable (unité purgée ou masquée)
            }

            yield [
                'siren' => $row['siren'],
                'legal_name' => $name,
                'normalized_name' => $this->normalizer->normalize($name) ?? '',
                'legal_form' => $row['categorieJuridiqueUniteLegale'] ?: null,
                'status' => match ($row['etatAdministratifUniteLegale'] ?? '') {
                    'A' => 'active',
                    'C' => 'ceased',
                    default => 'unknown',
                },
                'naf_code' => $row['activitePrincipaleUniteLegale'] ?: null,
                'employee_range' => $row['trancheEffectifsUniteLegale'] ?: null,
                'incorporated_at' => $row['dateCreationUniteLegale'] ?: null,
                'is_diffusible' => ($row['statutDiffusionUniteLegale'] ?? 'O') === 'O',
            ];
        }
    }

    public function establishments(): iterable
    {
        foreach ($this->readCsv($this->etablissementsPath) as $row) {
            if (($row['siret'] ?? '') === '') {
                continue;
            }

            // Filtre optionnel par département (démo « département test »,
            // note de cadrage §6.2). Corse : 2A/2B via le code postal 20xxx
            // non discriminant — le filtre s'appuie sur le code commune INSEE.
            if ($this->departmentFilter !== null
                && ! $this->matchesDepartment($row['codeCommuneEtablissement'] ?? '')) {
                continue;
            }

            $name = $row['enseigne1Etablissement']
                ?: ($row['denominationUsuelleEtablissement'] ?? '');

            yield [
                'siret' => $row['siret'],
                'siren' => $row['siren'],
                'name' => $name ?: null,
                'normalized_name' => $this->normalizer->normalize($name),
                'is_headquarters' => ($row['etablissementSiege'] ?? '') === 'true',
                'status' => match ($row['etatAdministratifEtablissement'] ?? '') {
                    'A' => 'active',
                    'F' => 'ceased',
                    default => 'unknown',
                },
                'naf_code' => $row['activitePrincipaleEtablissement'] ?: null,
                'employee_range' => $row['trancheEffectifsEtablissement'] ?: null,
                'address_line' => $this->buildAddressLine($row),
                'postal_code' => $row['codePostalEtablissement'] ?: null,
                'city' => $row['libelleCommuneEtablissement'] ?: null,
                'city_code' => $row['codeCommuneEtablissement'] ?: null,
                'department_code' => $this->departmentFromCityCode(
                    $row['codeCommuneEtablissement'] ?? '',
                ),
                // Variante géolocalisée du stock : coordonnées déjà fournies.
                'longitude' => isset($row['longitude']) && $row['longitude'] !== ''
                    ? (float) $row['longitude'] : null,
                'latitude' => isset($row['latitude']) && $row['latitude'] !== ''
                    ? (float) $row['latitude'] : null,
                // Stock standard : coordonnées Lambert-93 (métropole + Corse
                // uniquement — les DROM utilisent d'autres projections, leur
                // géocodage passera par la BAN).
                ...$this->lambertCoordinates($row),
            ];
        }
    }

    /**
     * Coordonnées Lambert-93 du stock INSEE, si présentes et exploitables.
     *
     * @param  array<string, string>  $row
     * @return array{lambert_x: float|null, lambert_y: float|null}
     */
    private function lambertCoordinates(array $row): array
    {
        $x = $row['coordonneeLambertAbscisseEtablissement'] ?? '';
        $y = $row['coordonneeLambertOrdonneeEtablissement'] ?? '';
        $department = $this->departmentFromCityCode($row['codeCommuneEtablissement'] ?? '');

        // Lambert-93 (SRID 2154) n'est défini que pour la métropole.
        $isMetropole = $department !== null && ! str_starts_with($department, '97');

        if (! $isMetropole || ! is_numeric($x) || ! is_numeric($y)) {
            return ['lambert_x' => null, 'lambert_y' => null];
        }

        return ['lambert_x' => (float) $x, 'lambert_y' => (float) $y];
    }

    /**
     * Lit un CSV SIRENE (éventuellement zippé) ligne à ligne.
     *
     * @return Generator<int, array<string, string>>
     */
    private function readCsv(string $path): Generator
    {
        $stream = str_ends_with($path, '.zip')
            ? $this->openZippedCsv($path)
            : fopen($path, 'rb');

        if ($stream === false) {
            throw new RuntimeException("Fichier SIRENE illisible : {$path}");
        }

        try {
            $headers = fgetcsv($stream, escape: '');

            if ($headers === false) {
                throw new RuntimeException("Fichier SIRENE vide : {$path}");
            }

            while (($values = fgetcsv($stream, escape: '')) !== false) {
                if (count($values) === count($headers)) {
                    // « [ND] » : champ masqué par l'INSEE (unité protégée) —
                    // traité comme absent (note de cadrage §3).
                    yield array_combine($headers, array_map(
                        static fn (string $v): string => $v === '[ND]' ? '' : $v,
                        $values,
                    ));
                }
            }
        } finally {
            fclose($stream);
        }
    }

    /** @return resource|false */
    private function openZippedCsv(string $path)
    {
        $zip = new \ZipArchive;

        if ($zip->open($path) !== true || $zip->numFiles < 1) {
            return false;
        }

        $inner = $zip->getNameIndex(0);
        $zip->close();

        return fopen("zip://{$path}#{$inner}", 'rb');
    }

    private function matchesDepartment(string $cityCode): bool
    {
        return $this->departmentFromCityCode($cityCode) === $this->departmentFilter;
    }

    /** Code département depuis le code commune INSEE (gère 2A/2B et DROM). */
    private function departmentFromCityCode(string $cityCode): ?string
    {
        if (strlen($cityCode) !== 5) {
            return null;
        }

        // Outre-mer : 97x / 98x sur trois caractères.
        if (str_starts_with($cityCode, '97') || str_starts_with($cityCode, '98')) {
            return substr($cityCode, 0, 3);
        }

        return substr($cityCode, 0, 2); // « 2A004 » → « 2A »
    }

    /** @param array<string, string> $row */
    private function buildAddressLine(array $row): ?string
    {
        $line = trim(implode(' ', array_filter([
            $row['numeroVoieEtablissement'] ?? '',
            $row['indiceRepetitionEtablissement'] ?? '',
            $row['typeVoieEtablissement'] ?? '',
            $row['libelleVoieEtablissement'] ?? '',
        ])));

        return $line === '' ? null : $line;
    }
}

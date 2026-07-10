<?php

use App\Models\Company;
use App\Models\Establishment;
use App\Models\Import;
use App\Services\Enrichment\Contracts\PoiConnector;
use App\Services\Enrichment\EnrichmentService;
use App\Services\Enrichment\Osm\OverpassConnector;
use App\Services\Ingestion\NameNormalizer;
use Database\Seeders\DepartmentSeeder;
use Database\Seeders\RegionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    $this->seed([RoleSeeder::class, RegionSeeder::class, DepartmentSeeder::class]);

    $company = Company::create([
        'siren' => '111111111', 'legal_name' => 'Dupont SAS',
        'normalized_name' => 'dupont', 'status' => 'active',
    ]);

    // Boulangerie géolocalisée à Bordeaux, sans téléphone ni site.
    $this->bakery = Establishment::create([
        'siret' => '11111111100011', 'company_id' => $company->id,
        'name' => 'Boulangerie Dupont', 'normalized_name' => 'boulangerie dupont',
        'status' => 'active', 'naf_code' => '10.71C', 'city' => 'BORDEAUX',
        'department_code' => '33',
        'location' => DB::raw('ST_SetSRID(ST_MakePoint(-0.5800, 44.8412), 4326)'),
    ]);

    // Garage voisin qui possède DÉJÀ un téléphone (précédence par champ).
    $this->garage = Establishment::create([
        'siret' => '11111111100029', 'company_id' => $company->id,
        'name' => 'Garage Rhône', 'normalized_name' => 'garage rhone',
        'status' => 'active', 'naf_code' => '45.20A', 'city' => 'BORDEAUX',
        'department_code' => '33', 'phone' => '+33 5 56 00 00 00',
        'location' => DB::raw('ST_SetSRID(ST_MakePoint(-0.5700, 44.8500), 4326)'),
    ]);
});

/** Doublure de connecteur : la même substituabilité que SIRENE (§5.3). */
function fakePois(array $pois): PoiConnector
{
    return new class($pois) implements PoiConnector
    {
        public function __construct(private readonly array $pois) {}

        public function pois(): iterable
        {
            yield from $this->pois;
        }
    };
}

it('enrichit un établissement apparié par proximité et nom (téléphone, site, horaires)', function (): void {
    $stats = app(EnrichmentService::class)->enrich(fakePois([[
        'name' => 'Boulangerie Dupont',
        'normalized_name' => 'boulangerie dupont',
        'latitude' => 44.84125, 'longitude' => -0.58004, // ~5 m
        'phone' => '+33 5 56 12 34 56',
        'website' => 'https://boulangerie-dupont.fr',
        'opening_hours' => 'Mo-Sa 07:00-19:30',
    ]]), 'osm');

    $this->bakery->refresh();
    expect($this->bakery->phone)->toBe('+33 5 56 12 34 56')
        ->and($this->bakery->website)->toBe('https://boulangerie-dupont.fr')
        ->and($this->bakery->opening_hours)->toBe(['raw' => 'Mo-Sa 07:00-19:30', 'source' => 'osm'])
        ->and($this->bakery->enriched_at)->not->toBeNull()
        ->and($stats['matched'])->toBe(1);
});

it('ne rapproche pas un POI au nom étranger, même tout proche', function (): void {
    $stats = app(EnrichmentService::class)->enrich(fakePois([[
        'name' => 'Pharmacie Centrale',
        'normalized_name' => 'pharmacie centrale',
        'latitude' => 44.84121, 'longitude' => -0.58001,
        'phone' => '+33 5 56 99 99 99',
    ]]), 'osm');

    expect($this->bakery->fresh()->phone)->toBeNull()
        ->and($stats['unmatched'])->toBe(1);
});

it('respecte la précédence par champ : un téléphone existant n\'est jamais écrasé (correctif 15)', function (): void {
    app(EnrichmentService::class)->enrich(fakePois([[
        'name' => 'Garage Rhone',
        'normalized_name' => 'garage rhone',
        'latitude' => 44.8500, 'longitude' => -0.5700,
        'phone' => '+33 5 56 11 11 11',
        'website' => 'https://garage-rhone.fr',
    ]]), 'osm');

    $this->garage->refresh();
    expect($this->garage->phone)->toBe('+33 5 56 00 00 00') // préservé
        ->and($this->garage->website)->toBe('https://garage-rhone.fr'); // complété
});

it('normalise les éléments Overpass : tags contact:*, centre des ways', function (): void {
    Http::fake([
        'overpass-api.de/*' => Http::response([
            'elements' => [
                [
                    'type' => 'node', 'id' => 1, 'lat' => 44.84, 'lon' => -0.58,
                    'tags' => [
                        'name' => 'Boulangerie Dupont',
                        'contact:phone' => '+33 5 56 12 34 56',
                        'contact:website' => 'boulangerie-dupont.fr',
                        'opening_hours' => 'Mo-Sa 07:00-19:30',
                    ],
                ],
                [
                    'type' => 'way', 'id' => 2, 'center' => ['lat' => 44.85, 'lon' => -0.57],
                    'tags' => ['name' => 'Garage Rhône', 'phone' => '+33 5 56 11 11 11'],
                ],
                // Sans nom ni coordonnées : ignoré.
                ['type' => 'node', 'id' => 3, 'lat' => 44.8, 'lon' => -0.6, 'tags' => ['phone' => 'x']],
            ],
        ]),
    ]);

    $connector = new OverpassConnector(app(NameNormalizer::class), '33');
    $pois = iterator_to_array($connector->pois(), false);

    expect($pois)->toHaveCount(2)
        ->and($pois[0]['phone'])->toBe('+33 5 56 12 34 56')
        ->and($pois[0]['website'])->toBe('https://boulangerie-dupont.fr') // schéma ajouté
        ->and($pois[0]['normalized_name'])->toBe('boulangerie dupont')
        ->and($pois[1]['latitude'])->toBe(44.85) // centre du way
        ->and($pois[1]['opening_hours'])->toBeNull();
});

it('trace l\'enrichissement dans la table imports via la commande (EF-04.5)', function (): void {
    Http::fake([
        'overpass-api.de/*' => Http::response(['elements' => [[
            'type' => 'node', 'id' => 1, 'lat' => 44.84125, 'lon' => -0.58004,
            'tags' => ['name' => 'Boulangerie Dupont', 'phone' => '+33 5 56 12 34 56'],
        ]]]),
    ]);

    $this->artisan('fbde:osm:enrich', ['--department' => '33'])->assertSuccessful();

    $import = Import::latest('id')->firstOrFail();
    expect($import->source)->toBe('osm')
        ->and($import->status)->toBe('completed')
        ->and((int) $import->stats['matched'])->toBe(1);

    expect($this->bakery->fresh()->phone)->toBe('+33 5 56 12 34 56');
});

<?php

use App\Models\Company;
use App\Models\Establishment;
use App\Models\User;
use Database\Seeders\DepartmentSeeder;
use Database\Seeders\RegionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    $this->seed([RoleSeeder::class, RegionSeeder::class, DepartmentSeeder::class]);

    $this->commercial = User::factory()->create()->syncRoles('commercial');

    $company = Company::create([
        'siren' => '222222222',
        'legal_name' => 'Cartographie Test SAS',
        'normalized_name' => 'cartographie test',
        'status' => 'active',
    ]);

    // Deux actifs dans Paris intra-muros (emprise de test), NAF distincts.
    Establishment::create([
        'siret' => '22222222200011', 'company_id' => $company->id,
        'name' => 'Boulangerie Carte', 'normalized_name' => 'boulangerie carte',
        'status' => 'active', 'naf_code' => '10.71C',
        'postal_code' => '75002', 'city' => 'PARIS', 'city_code' => '75102',
        'department_code' => '75',
        'location' => DB::raw('ST_SetSRID(ST_MakePoint(2.3312, 48.8695), 4326)'),
    ]);
    Establishment::create([
        'siret' => '22222222200029', 'company_id' => $company->id,
        'name' => 'Garage Carte', 'normalized_name' => 'garage carte',
        'status' => 'active', 'naf_code' => '45.20A',
        'postal_code' => '75011', 'city' => 'PARIS', 'city_code' => '75111',
        'department_code' => '75',
        'location' => DB::raw('ST_SetSRID(ST_MakePoint(2.3800, 48.8570), 4326)'),
    ]);

    // Actif mais hors emprise (Lyon).
    Establishment::create([
        'siret' => '22222222200037', 'company_id' => $company->id,
        'name' => 'Garage Lyon', 'normalized_name' => 'garage lyon',
        'status' => 'active', 'naf_code' => '45.20A',
        'postal_code' => '69007', 'city' => 'LYON', 'city_code' => '69387',
        'department_code' => '69',
        'location' => DB::raw('ST_SetSRID(ST_MakePoint(4.8423, 45.7458), 4326)'),
    ]);

    // Fermé dans l'emprise : exclu par défaut (statut actif).
    Establishment::create([
        'siret' => '22222222200045', 'company_id' => $company->id,
        'name' => 'Ancien Carte', 'normalized_name' => 'ancien carte',
        'status' => 'ceased', 'naf_code' => '10.71C',
        'postal_code' => '75002', 'city' => 'PARIS', 'city_code' => '75102',
        'department_code' => '75',
        'location' => DB::raw('ST_SetSRID(ST_MakePoint(2.3400, 48.8600), 4326)'),
    ]);

    // Actif dans le département mais jamais géolocalisé : absent de la carte.
    Establishment::create([
        'siret' => '22222222200052', 'company_id' => $company->id,
        'name' => 'Sans Position', 'normalized_name' => 'sans position',
        'status' => 'active', 'naf_code' => '10.71C',
        'postal_code' => '75002', 'city' => 'PARIS', 'city_code' => '75102',
        'department_code' => '75',
    ]);

    // Emprise Paris intra-muros : contient les deux actifs parisiens.
    $this->parisBbox = '2.25,48.81,2.42,48.90';
});

it('refuse la carte sans authentification puis sans permission', function (): void {
    $this->getJson('/api/v1/companies/map?bbox='.$this->parisBbox)->assertUnauthorized();

    $noRole = User::factory()->create();
    $this->actingAs($noRole)
        ->getJson('/api/v1/companies/map?bbox='.$this->parisBbox)
        ->assertForbidden();
});

it('sert les points de l\'emprise : actifs géolocalisés uniquement (correctif 16)', function (): void {
    $response = $this->actingAs($this->commercial)
        ->getJson('/api/v1/companies/map?bbox='.$this->parisBbox);

    $response->assertOk();
    expect($response->json('data.mode'))->toBe('points')
        ->and($response->json('data.total'))->toBe(2);

    $sirets = collect($response->json('data.points'))->pluck('siret');
    expect($sirets)->toContain('22222222200011')
        ->toContain('22222222200029')
        ->not->toContain('22222222200037') // hors emprise
        ->not->toContain('22222222200045') // fermé
        ->not->toContain('22222222200052'); // sans position

    // Charge utile légère : id + libellé + coordonnées, pas la fiche complète.
    $point = collect($response->json('data.points'))->firstWhere('siret', '22222222200011');
    expect($point['name'])->toBe('Boulangerie Carte')
        ->and($point['longitude'])->toBe(2.3312)
        ->and($point['latitude'])->toBe(48.8695)
        ->and($point)->toHaveKey('id')
        ->and($point)->not->toHaveKey('company');
});

it('rejette les emprises absentes ou malformées', function (string $query): void {
    $this->actingAs($this->commercial)
        ->getJson('/api/v1/companies/map'.$query)
        ->assertUnprocessable();
})->with([
    'bbox manquante' => '',
    'trois composantes' => '?bbox=2.25,48.81,2.42',
    'non numérique' => '?bbox=a,b,c,d',
    'longitude hors limites' => '?bbox=-190,48.81,2.42,48.90',
    'min supérieur au max' => '?bbox=2.42,48.81,2.25,48.90',
]);

it('combine l\'emprise avec les filtres de recherche existants', function (): void {
    $response = $this->actingAs($this->commercial)
        ->getJson('/api/v1/companies/map?bbox='.$this->parisBbox.'&naf=45');

    expect($response->json('data.total'))->toBe(1)
        ->and($response->json('data.points.0.siret'))->toBe('22222222200029');
});

it('bascule en agrégats serveur au-delà du plafond de points', function (): void {
    config(['fbde.map_points_limit' => 1]); // plafond abaissé pour le test

    $response = $this->actingAs($this->commercial)
        ->getJson('/api/v1/companies/map?bbox='.$this->parisBbox);

    $response->assertOk();
    expect($response->json('data.mode'))->toBe('clusters')
        ->and($response->json('data.total'))->toBe(2)
        ->and($response->json('data'))->not->toHaveKey('points');

    // Chaque cellule porte un centre et un compteur ; la somme couvre tout.
    $clusters = collect($response->json('data.clusters'));
    expect($clusters->sum('count'))->toBe(2);
    $clusters->each(function (array $cell): void {
        expect($cell)->toHaveKeys(['longitude', 'latitude', 'count']);
    });
});

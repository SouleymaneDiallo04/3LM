<?php

use App\Models\Company;
use App\Models\Establishment;
use App\Models\Import;
use App\Services\Scoring\ScoringService;
use Database\Seeders\DepartmentSeeder;
use Database\Seeders\RegionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    $this->seed([RoleSeeder::class, RegionSeeder::class, DepartmentSeeder::class]);

    $company = Company::create([
        'siren' => '111111111', 'legal_name' => 'Dupont SAS',
        'normalized_name' => 'dupont', 'status' => 'active',
        'employee_range' => '12',
    ]);

    // Fiche riche : tous les signaux au vert.
    $this->rich = Establishment::create([
        'siret' => '11111111100011', 'company_id' => $company->id,
        'name' => 'Boulangerie Dupont', 'normalized_name' => 'boulangerie dupont',
        'status' => 'active', 'naf_code' => '10.71C', 'city' => 'BORDEAUX',
        'department_code' => '33', 'employee_range' => '12',
        'email' => 'contact@dupont.fr', 'phone' => '+33 5 56 12 34 56',
        'website' => 'https://dupont.fr',
        'opening_hours' => ['raw' => 'Mo-Sa 07:00-19:30', 'source' => 'osm'],
        'rating' => 4.5, 'reviews_count' => 200, 'rating_source' => 'site_jsonld',
        'location' => DB::raw('ST_SetSRID(ST_MakePoint(-0.58, 44.84), 4326)'),
    ]);

    // Fiche nue : aucun signal au-delà du statut actif.
    $this->bare = Establishment::create([
        'siret' => '11111111100029', 'company_id' => $company->id,
        'name' => 'Sans Rien', 'normalized_name' => 'sans rien',
        'status' => 'active', 'city' => 'BORDEAUX', 'department_code' => '33',
    ]);
});

it('calcule un indice de réputation déterministe 0-100 depuis la note JSON-LD', function (): void {
    app(ScoringService::class)->score($this->rich);
    $this->rich->refresh();

    // 4,5/5 → 63/70 points de note ; 200 avis = plafond volume → 30/30.
    expect($this->rich->reputation_score)->toBe(93)
        ->and($this->rich->score_factors['reputation']['rating_points'])->toBe(63)
        ->and($this->rich->score_factors['reputation']['volume_points'])->toBe(30);

    // Sans note : pas d'indice inventé.
    app(ScoringService::class)->score($this->bare);
    expect($this->bare->fresh()->reputation_score)->toBeNull();
});

it('calcule un score commercial 100 % règles, pondérations versionnées (EF-08.1)', function (): void {
    app(ScoringService::class)->score($this->rich);
    $this->rich->refresh();

    // email 15 + tél 15 + site 10 + géoloc 10 + effectifs 10 + horaires 10
    // + réputation 93×0,30 ≈ 28 → 98.
    expect($this->rich->commercial_score)->toBe(98)
        ->and($this->rich->score_factors['version'])->not->toBeNull()
        ->and($this->rich->score_factors['commercial']['email'])->toBe(15)
        ->and($this->rich->score_factors['commercial']['reputation'])->toBe(28);

    app(ScoringService::class)->score($this->bare);
    expect($this->bare->fresh()->commercial_score)->toBe(0);
});

it('est déterministe : recalculer ne change rien', function (): void {
    $service = app(ScoringService::class);
    $service->score($this->rich);
    $first = $this->rich->fresh()->commercial_score;

    $service->score($this->rich->fresh());

    expect($this->rich->fresh()->commercial_score)->toBe($first);
});

it('recalcule en masse et trace l\'exécution (commande fbde:score)', function (): void {
    $this->artisan('fbde:score', ['--department' => '33'])->assertSuccessful();

    $import = Import::latest('id')->firstOrFail();
    expect($import->source)->toBe('scoring')
        ->and($import->status)->toBe('completed')
        ->and((int) $import->stats['scored'])->toBe(2);

    expect($this->rich->fresh()->commercial_score)->toBe(98);
});

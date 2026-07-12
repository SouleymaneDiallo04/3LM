<?php

use App\Models\Company;
use App\Models\Establishment;
use App\Models\User;
use App\Services\Scoring\ScoringService;
use Database\Seeders\DepartmentSeeder;
use Database\Seeders\RegionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    $this->seed([RoleSeeder::class, RegionSeeder::class, DepartmentSeeder::class]);
    $this->commercial = User::factory()->create()->syncRoles('commercial');

    $company = Company::create([
        'siren' => '111111111', 'legal_name' => 'Dupont SAS',
        'normalized_name' => 'dupont', 'status' => 'active',
    ]);

    // Quatre fiches à scores échelonnés couvrant les 4 paliers.
    $make = function (string $siret, string $name, int $score) use ($company): Establishment {
        return Establishment::create([
            'siret' => $siret, 'company_id' => $company->id,
            'name' => $name, 'normalized_name' => mb_strtolower($name),
            'status' => 'active', 'naf_code' => '10.71C', 'city' => 'BORDEAUX',
            'department_code' => '33', 'commercial_score' => $score,
            'location' => DB::raw('ST_SetSRID(ST_MakePoint(-0.58, 44.84), 4326)'),
        ]);
    };
    $this->a = $make('11111111100011', 'Excellente', 82); // A
    $this->b = $make('11111111100029', 'Bonne', 55);      // B
    $this->c = $make('11111111100037', 'Moyenne', 25);    // C
    $this->d = $make('11111111100045', 'Faible', 5);      // D
});

it('classe chaque entreprise en palier déterministe A-D depuis son score (EF-08.1)', function (): void {
    expect(app(ScoringService::class)->tier(82))->toBe('A')
        ->and(app(ScoringService::class)->tier(55))->toBe('B')
        ->and(app(ScoringService::class)->tier(25))->toBe('C')
        ->and(app(ScoringService::class)->tier(5))->toBe('D')
        ->and(app(ScoringService::class)->tier(null))->toBeNull();
});

it('expose le palier dans la fiche', function (): void {
    $id = $this->a->id;
    $response = $this->actingAs($this->commercial)->getJson("/api/v1/companies/{$id}");

    $response->assertOk();
    expect($response->json('data.commercial_score'))->toBe(82)
        ->and($response->json('data.commercial_tier'))->toBe('A');
});

it('filtre les meilleurs prospects par palier minimum (détection des meilleurs prospects)', function (): void {
    // min_tier=B → paliers A et B seulement.
    $response = $this->actingAs($this->commercial)
        ->getJson('/api/v1/companies?min_tier=B&sort=commercial_score&direction=desc');

    $sirets = collect($response->json('data'))->pluck('siret');
    expect($sirets)->toHaveCount(2)
        ->and($sirets->first())->toBe('11111111100011') // A en tête (tri score desc)
        ->and($sirets)->not->toContain('11111111100037'); // C exclu
});

it('rejette un palier invalide', function (): void {
    $this->actingAs($this->commercial)
        ->getJson('/api/v1/companies?min_tier=Z')
        ->assertUnprocessable();
});

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
        'siren' => '111111111',
        'legal_name' => 'Boulangerie Dupont SAS',
        'normalized_name' => 'boulangerie dupont',
        'status' => 'active',
    ]);

    // Paris, boulangerie active, email présent.
    Establishment::create([
        'siret' => '11111111100011', 'company_id' => $company->id,
        'name' => 'Boulangerie Dupont', 'normalized_name' => 'boulangerie dupont',
        'status' => 'active', 'naf_code' => '10.71C',
        'postal_code' => '75002', 'city' => 'PARIS', 'city_code' => '75102',
        'department_code' => '75', 'email' => 'contact@dupont.fr',
        'location' => DB::raw('ST_SetSRID(ST_MakePoint(2.3312, 48.8695), 4326)'),
    ]);

    // Lyon, garage actif, sans email.
    Establishment::create([
        'siret' => '11111111100029', 'company_id' => $company->id,
        'name' => 'Garage Rhône', 'normalized_name' => 'garage rhone',
        'status' => 'active', 'naf_code' => '45.20A',
        'postal_code' => '69007', 'city' => 'LYON', 'city_code' => '69387',
        'department_code' => '69',
        'location' => DB::raw('ST_SetSRID(ST_MakePoint(4.8423, 45.7458), 4326)'),
    ]);

    // Paris, fermé.
    Establishment::create([
        'siret' => '11111111100037', 'company_id' => $company->id,
        'name' => 'Ancien Dupont', 'normalized_name' => 'ancien dupont',
        'status' => 'ceased', 'naf_code' => '10.71C',
        'postal_code' => '75011', 'city' => 'PARIS', 'city_code' => '75111',
        'department_code' => '75',
    ]);
});

it('refuse la recherche sans authentification puis sans permission', function (): void {
    $this->getJson('/api/v1/companies')->assertUnauthorized();

    $noRole = User::factory()->create(); // aucun rôle → aucune permission
    $this->actingAs($noRole)->getJson('/api/v1/companies')->assertForbidden();
});

it('liste les établissements actifs par défaut, avec fiche complète', function (): void {
    $response = $this->actingAs($this->commercial)->getJson('/api/v1/companies');

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(2); // le fermé est exclu

    $bakery = collect($response->json('data'))->firstWhere('siret', '11111111100011');
    expect($bakery['name'])->toBe('Boulangerie Dupont')
        ->and($bakery['address']['city'])->toBe('PARIS')
        ->and($bakery['coordinates']['longitude'])->toBe(2.3312)
        ->and($bakery['company']['siren'])->toBe('111111111');
});

it('combine librement les filtres (EF-01.4) : département + NAF + email', function (): void {
    $response = $this->actingAs($this->commercial)
        ->getJson('/api/v1/companies?department=75&naf=10.71C&has_email=1');

    expect($response->json('data'))->toHaveCount(1)
        ->and($response->json('data.0.siret'))->toBe('11111111100011');

    // Préfixe de division NAF (EF-01.1).
    $division = $this->actingAs($this->commercial)->getJson('/api/v1/companies?naf=45');
    expect($division->json('data.0.siret'))->toBe('11111111100029');
});

it('recherche par rayon géographique via l\'API (EF-01.3)', function (): void {
    $paris = $this->actingAs($this->commercial)
        ->getJson('/api/v1/companies?lat=48.8695&lng=2.3312&radius_km=5');

    expect($paris->json('data'))->toHaveCount(1)
        ->and($paris->json('data.0.siret'))->toBe('11111111100011');

    // Rayon sans centre : rejeté par la validation.
    $this->actingAs($this->commercial)
        ->getJson('/api/v1/companies?radius_km=5')
        ->assertUnprocessable();
});

it('recherche par région via le référentiel (EF-01.2)', function (): void {
    // 75 → Île-de-France (11) ; 69 → Auvergne-Rhône-Alpes (84).
    $idf = $this->actingAs($this->commercial)->getJson('/api/v1/companies?region=11');
    expect($idf->json('data'))->toHaveCount(1)
        ->and($idf->json('data.0.siret'))->toBe('11111111100011');

    // Région inconnue : rejetée par la validation.
    $this->actingAs($this->commercial)
        ->getJson('/api/v1/companies?region=99')
        ->assertUnprocessable();
});

it('sert le référentiel régions + départements pour les filtres', function (): void {
    $response = $this->actingAs($this->commercial)->getJson('/api/v1/referentiels');

    $response->assertOk();
    $regions = collect($response->json('data.regions'));
    expect($regions->count())->toBeGreaterThanOrEqual(18);

    $idf = $regions->firstWhere('code', '11');
    expect($idf['name'])->not->toBeNull()
        ->and(collect($idf['departments'])->pluck('code'))->toContain('75');
});

it('filtre par note minimale, volume d\'avis et taille d\'entreprise (recherche avancée CDC)', function (): void {
    Establishment::where('siret', '11111111100011')->update([
        'rating' => 4.6, 'reviews_count' => 87, 'employee_range' => '12', // 20-49 salariés
    ]);
    Establishment::where('siret', '11111111100029')->update([
        'rating' => 3.2, 'reviews_count' => 4, 'employee_range' => '03', // 6-9 salariés
    ]);

    // Note ≥ 4 : seule la boulangerie.
    $rated = $this->actingAs($this->commercial)->getJson('/api/v1/companies?min_rating=4');
    expect($rated->json('data'))->toHaveCount(1)
        ->and($rated->json('data.0.siret'))->toBe('11111111100011');

    // Au moins 50 avis.
    $reviewed = $this->actingAs($this->commercial)->getJson('/api/v1/companies?min_reviews=50');
    expect($reviewed->json('data'))->toHaveCount(1);

    // Effectif minimal 10+ (code tranche 11) : exclut le 6-9 salariés.
    $sized = $this->actingAs($this->commercial)->getJson('/api/v1/companies?min_employees=11');
    expect($sized->json('data'))->toHaveCount(1)
        ->and($sized->json('data.0.siret'))->toBe('11111111100011');

    // Bornes invalides rejetées.
    $this->actingAs($this->commercial)->getJson('/api/v1/companies?min_rating=6')->assertUnprocessable();
    $this->actingAs($this->commercial)->getJson('/api/v1/companies?min_employees=07')->assertUnprocessable();
});

it('recherche textuelle insensible aux accents et à la casse (EF-01.1)', function (): void {
    $response = $this->actingAs($this->commercial)
        ->getJson('/api/v1/companies?q='.urlencode('BOULANGÈRIE'));

    // « boulangerie » après normalisation → correspond via ILIKE partiel.
    expect(collect($response->json('data'))->pluck('siret'))->toContain('11111111100011');
});

it('pagine par curseur (§9.2) avec liens next', function (): void {
    $response = $this->actingAs($this->commercial)
        ->getJson('/api/v1/companies?per_page=1');

    $response->assertOk()->assertJsonStructure(['data', 'links' => ['next'], 'meta']);
    expect($response->json('data'))->toHaveCount(1)
        ->and($response->json('links.next'))->not->toBeNull();

    // La page suivante contient l'établissement suivant, sans doublon.
    $next = $this->actingAs($this->commercial)->getJson($response->json('links.next'));
    expect($next->json('data.0.siret'))->not->toBe($response->json('data.0.siret'));
});

it('sert la fiche individuelle avec unité légale et coordonnées', function (): void {
    $id = Establishment::where('siret', '11111111100011')->value('id');

    $response = $this->actingAs($this->commercial)->getJson("/api/v1/companies/{$id}");

    $response->assertOk();
    expect($response->json('data.siret'))->toBe('11111111100011')
        ->and($response->json('data.company.legal_name'))->toBe('Boulangerie Dupont SAS')
        ->and($response->json('data.coordinates.latitude'))->toBe(48.8695);
});

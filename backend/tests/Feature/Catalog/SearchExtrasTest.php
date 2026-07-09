<?php

use App\Jobs\RecordSearchJob;
use App\Models\Company;
use App\Models\Establishment;
use App\Models\Search;
use App\Models\User;
use App\Services\Catalog\EstablishmentSearch;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->user = User::factory()->create()->syncRoles('commercial');
    Cache::flush();

    $company = Company::create([
        'siren' => '111111111', 'legal_name' => 'Dupont SAS',
        'normalized_name' => 'dupont', 'status' => 'active',
    ]);

    Establishment::create([
        'siret' => '11111111100011', 'company_id' => $company->id,
        'name' => 'Boulangerie A', 'normalized_name' => 'boulangerie a',
        'status' => 'active', 'naf_code' => '10.71C', 'city' => 'PARIS',
        'department_code' => null, 'commercial_score' => 80,
        'imported_at' => now()->subDays(2),
    ]);
    Establishment::create([
        'siret' => '11111111100029', 'company_id' => $company->id,
        'name' => 'Garage B', 'normalized_name' => 'garage b',
        'status' => 'active', 'naf_code' => '45.20A', 'city' => 'LYON',
        'commercial_score' => 40, 'imported_at' => now()->subDays(40),
    ]);
});

it('historise la recherche initiale, pas les pages suivantes (EF-01.6)', function (): void {
    Queue::fake();

    $this->actingAs($this->user)->getJson('/api/v1/companies?q=boulangerie&per_page=1');
    Queue::assertPushed(RecordSearchJob::class, 1);

    // Page suivante (curseur) : pas de nouvelle historisation.
    $this->actingAs($this->user)->getJson('/api/v1/companies?q=boulangerie&per_page=1&cursor=abc');
    Queue::assertPushed(RecordSearchJob::class, 1);
});

it('enregistre le nombre total de résultats dans l\'historique', function (): void {
    (new RecordSearchJob($this->user->id, ['q' => 'boulangerie']))
        ->handle(app(EstablishmentSearch::class));

    $search = Search::firstOrFail();
    expect($search->keyword)->toBe('boulangerie')
        ->and($search->results_count)->toBe(1)
        ->and($search->user_id)->toBe($this->user->id);
});

it('liste l\'historique du seul utilisateur courant', function (): void {
    Search::create(['user_id' => $this->user->id, 'keyword' => 'mien', 'status' => 'completed']);
    $other = User::factory()->create()->syncRoles('commercial');
    Search::create(['user_id' => $other->id, 'keyword' => 'autre', 'status' => 'completed']);

    $response = $this->actingAs($this->user)->getJson('/api/v1/searches');

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(1)
        ->and($response->json('data.0.keyword'))->toBe('mien');
});

it('trie les résultats par score commercial décroissant (EF-03.6)', function (): void {
    Queue::fake();

    $response = $this->actingAs($this->user)
        ->getJson('/api/v1/companies?sort=commercial_score&direction=desc');

    expect($response->json('data.0.siret'))->toBe('11111111100011') // score 80
        ->and($response->json('data.1.siret'))->toBe('11111111100029'); // score 40

    // Champ de tri non autorisé : rejeté.
    $this->actingAs($this->user)
        ->getJson('/api/v1/companies?sort=email')
        ->assertUnprocessable();
});

it('sert les facettes du résultat filtré courant (EF-03.3)', function (): void {
    Queue::fake();

    $response = $this->actingAs($this->user)->getJson('/api/v1/companies/facets');

    $response->assertOk();
    $divisions = collect($response->json('data.naf_divisions'));
    expect($divisions->firstWhere('code', '10')['count'])->toBe(1)
        ->and($divisions->firstWhere('code', '45')['count'])->toBe(1);

    // Filtré : seule la division du filtre reste.
    $filtered = $this->actingAs($this->user)->getJson('/api/v1/companies/facets?naf=10');
    expect(collect($filtered->json('data.naf_divisions')))->toHaveCount(1);
});

it('sert les KPI et répartitions du dashboard (EF-06.1, EF-06.2)', function (): void {
    $response = $this->actingAs($this->user)->getJson('/api/v1/statistics');

    $response->assertOk();
    $kpis = $response->json('data.kpis');

    expect($kpis['active_establishments'])->toBe(2)
        ->and($kpis['new_last_7_days'])->toBe(1)
        ->and($kpis['new_last_30_days'])->toBe(1)
        ->and($kpis['email_rate'])->toBe(0)
        ->and($response->json('data.top_cities'))->toHaveCount(2);
});

it('refuse les statistiques sans la permission statistics.view', function (): void {
    $noRole = User::factory()->create();

    $this->actingAs($noRole)->getJson('/api/v1/statistics')->assertForbidden();
});

// Le cache Redis de Laravel 13 refuse de désérialiser les objets
// (__PHP_Incomplete_Class) : le payload mis en cache doit être 100 % scalaire.
// Reproduit le crash réel du dashboard (top_cities devenait un objet vide).
it('sert les statistiques intactes depuis le cache Redis (2e appel)', function (): void {
    config(['cache.default' => 'redis']);
    Cache::store('redis')->forget('statistics:dashboard');

    $fresh = $this->actingAs($this->user)->getJson('/api/v1/statistics');
    $cached = $this->actingAs($this->user)->getJson('/api/v1/statistics');

    $cached->assertOk();
    expect($cached->json('data'))->toBe($fresh->json('data'))
        ->and($cached->json('data.top_cities.0.city'))->not->toBeNull()
        ->and($cached->json('data.by_naf_division.0.code'))->not->toBeNull()
        ->and($cached->json('data.recent_imports'))->toBeArray();

    Cache::store('redis')->forget('statistics:dashboard');
});

<?php

use App\Models\Company;
use App\Models\Establishment;
use App\Models\User;
use App\Services\Ai\Contracts\AiClient;
use App\Services\Ai\FakeAiClient;
use Database\Seeders\DepartmentSeeder;
use Database\Seeders\RegionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    $this->seed([RoleSeeder::class, RegionSeeder::class, DepartmentSeeder::class]);
    $this->user = User::factory()->create()->syncRoles('commercial');
    $company = Company::create([
        'siren' => '111111111', 'legal_name' => 'Dupont SAS',
        'normalized_name' => 'dupont', 'status' => 'active',
    ]);
    $this->e = Establishment::create([
        'siret' => '11111111100011', 'company_id' => $company->id,
        'name' => 'Boulangerie Dupont', 'normalized_name' => 'boulangerie dupont',
        'status' => 'active', 'naf_code' => '10.71C', 'city' => 'BORDEAUX',
        'department_code' => '33',
    ]);
});

it('expose le résumé et un indicateur de péremption dans la fiche', function (): void {
    $this->e->update([
        'ai_summary' => 'Résumé.', 'ai_summary_version' => '1.0.0',
        'ai_summary_at' => now()->subDay(), 'enriched_at' => now(), // enrichie après le résumé
    ]);
    $id = $this->e->id;

    $data = $this->actingAs($this->user)->getJson("/api/v1/companies/{$id}")->json('data');

    expect($data['ai_summary'])->toBe('Résumé.')
        ->and($data['ai_summary_version'])->toBe('1.0.0')
        ->and($data['ai_summary_stale'])->toBeTrue(); // enriched_at > ai_summary_at
});

it('génère, stocke et renvoie le résumé (EF-08.2)', function (): void {
    app(FakeAiClient::class)->response = 'Boulangerie artisanale à Bordeaux.';
    $id = $this->e->id;

    $response = $this->actingAs($this->user)->postJson("/api/v1/companies/{$id}/summary");

    $response->assertOk();
    expect($response->json('data.summary'))->toBe('Boulangerie artisanale à Bordeaux.')
        ->and($response->json('data.version'))->toBe('1.0.0')
        ->and($this->e->fresh()->ai_summary)->toBe('Boulangerie artisanale à Bordeaux.')
        ->and($this->e->fresh()->ai_summary_version)->toBe('1.0.0');
});

it('renvoie le cache sans rappeler l\'IA, sauf refresh', function (): void {
    $this->e->update(['ai_summary' => 'Ancien.', 'ai_summary_version' => '1.0.0', 'ai_summary_at' => now()]);
    $fake = app(FakeAiClient::class);
    $fake->response = 'Nouveau.';
    $id = $this->e->id;

    // Sans refresh : cache renvoyé, IA non appelée.
    $cached = $this->actingAs($this->user)->postJson("/api/v1/companies/{$id}/summary");
    expect($cached->json('data.summary'))->toBe('Ancien.')->and($fake->calls)->toHaveCount(0);

    // Avec refresh : régénère.
    $fresh = $this->actingAs($this->user)->postJson("/api/v1/companies/{$id}/summary?refresh=1");
    expect($fresh->json('data.summary'))->toBe('Nouveau.')->and($fake->calls)->toHaveCount(1);
});

it('refuse la génération pour une fiche non-diffusible (RGPD)', function (): void {
    // is_diffusible est porté par l'unité légale (table companies).
    $this->e->company->update(['is_diffusible' => false]);
    $id = $this->e->id;

    $this->actingAs($this->user)->postJson("/api/v1/companies/{$id}/summary")
        ->assertStatus(422);
});

it('refuse la génération pour une fiche en liste d\'exclusion (RGPD)', function (): void {
    DB::table('exclusion_list')->insert([
        'identifier_type' => 'siren', 'identifier_value' => '111111111',
        'reason' => 'opposition', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $id = $this->e->id;

    $this->actingAs($this->user)->postJson("/api/v1/companies/{$id}/summary")
        ->assertStatus(422);
});

it('refuse le résumé en cache si la fiche est devenue non-diffusible (RGPD)', function (): void {
    // Résumé généré alors que la fiche était diffusible…
    $this->e->update(['ai_summary' => 'Ancien résumé.', 'ai_summary_version' => '1.0.0', 'ai_summary_at' => now()]);
    // …puis opposition postérieure : l'unité légale passe non-diffusible.
    $this->e->company->update(['is_diffusible' => false]);
    $id = $this->e->id;

    // Même sans refresh, la garde RGPD doit être ré-évaluée avant de servir le cache.
    $this->actingAs($this->user)->postJson("/api/v1/companies/{$id}/summary")
        ->assertStatus(422);
});

it('renvoie 503 si le service IA échoue', function (): void {
    // Doublure qui lève, injectée pour ce test.
    $this->app->bind(AiClient::class, function () {
        return new class implements AiClient
        {
            public function chat(array $messages, array $options = []): string
            {
                throw new RuntimeException('API down');
            }
        };
    });
    $id = $this->e->id;

    $this->actingAs($this->user)->postJson("/api/v1/companies/{$id}/summary")
        ->assertStatus(503);
});

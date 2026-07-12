<?php

use App\Models\Company;
use App\Models\Establishment;
use App\Models\User;
use App\Services\Ai\FakeAiClient;
use Database\Seeders\DepartmentSeeder;
use Database\Seeders\RegionSeeder;
use Database\Seeders\RoleSeeder;

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

it('génère un argumentaire à la demande sans le stocker (EF-08.4)', function (): void {
    app(FakeAiClient::class)->response = 'Bonjour, je vous contacte au sujet de…';
    $id = $this->e->id;

    $response = $this->actingAs($this->user)
        ->postJson("/api/v1/companies/{$id}/pitch", ['channel' => 'email']);

    $response->assertOk();
    expect($response->json('data.pitch'))->toBe('Bonjour, je vous contacte au sujet de…')
        ->and($response->json('data.channel'))->toBe('email')
        ->and($response->json('data.version'))->toBe('1.0.0');
    // Rien n'est persisté.
    expect($this->e->fresh()->ai_summary)->toBeNull();
});

it('valide le canal', function (): void {
    $id = $this->e->id;
    $this->actingAs($this->user)
        ->postJson("/api/v1/companies/{$id}/pitch", ['channel' => 'fax'])
        ->assertStatus(422);
});

it('refuse l\'argumentaire pour une fiche non-diffusible (RGPD)', function (): void {
    // is_diffusible est porté par l'unité légale (table companies).
    $this->e->company->update(['is_diffusible' => false]);
    $id = $this->e->id;
    $this->actingAs($this->user)
        ->postJson("/api/v1/companies/{$id}/pitch", ['channel' => 'call'])
        ->assertStatus(422);
});

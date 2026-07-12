<?php

use App\Models\Company;
use App\Models\Establishment;
use App\Models\Import;
use App\Services\Ai\FakeAiClient;
use Database\Seeders\DepartmentSeeder;
use Database\Seeders\RegionSeeder;
use Database\Seeders\RoleSeeder;

beforeEach(function (): void {
    $this->seed([RoleSeeder::class, RegionSeeder::class, DepartmentSeeder::class]);
    // is_diffusible est porté par l'unité légale : une company diffusible, une non.
    $diffusible = Company::create([
        'siren' => '111111111', 'legal_name' => 'Dupont SAS',
        'normalized_name' => 'dupont', 'status' => 'active', 'is_diffusible' => true,
    ]);
    $nonDiffusible = Company::create([
        'siren' => '222222222', 'legal_name' => 'Martin EI',
        'normalized_name' => 'martin', 'status' => 'active', 'is_diffusible' => false,
    ]);
    Establishment::create([
        'siret' => '11111111100011', 'company_id' => $diffusible->id,
        'name' => 'A', 'normalized_name' => 'a', 'status' => 'active',
        'city' => 'BORDEAUX', 'department_code' => '33',
    ]);
    // Unité légale non-diffusible : ignorée par la commande.
    Establishment::create([
        'siret' => '22222222200011', 'company_id' => $nonDiffusible->id,
        'name' => 'B', 'normalized_name' => 'b', 'status' => 'active',
        'city' => 'BORDEAUX', 'department_code' => '33',
    ]);
});

it('pré-génère les résumés diffusibles et trace l\'exécution', function (): void {
    app(FakeAiClient::class)->response = 'Résumé batch.';

    $this->artisan('fbde:ai:summarize', ['--department' => '33', '--limit' => 10])
        ->assertSuccessful();

    expect(Establishment::where('siret', '11111111100011')->value('ai_summary'))->toBe('Résumé batch.')
        ->and(Establishment::where('siret', '22222222200011')->value('ai_summary'))->toBeNull();

    $import = Import::latest('id')->firstOrFail();
    expect($import->source)->toBe('ai_summary')
        ->and($import->status)->toBe('completed')
        ->and((int) $import->stats['generated'])->toBe(1);
});

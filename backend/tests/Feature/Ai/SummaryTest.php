<?php

use App\Models\Company;
use App\Models\Establishment;
use App\Models\User;
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

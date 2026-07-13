<?php

// backend/tests/Feature/Ai/SimilarTest.php
use App\Models\Company;
use App\Models\Establishment;
use App\Models\User;
use Database\Seeders\DepartmentSeeder;
use Database\Seeders\RegionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;

function seedVec(Establishment $e, array $vec): void
{
    DB::update('UPDATE establishments SET embedding = ?::vector, embedded_at = now() WHERE id = ?',
        ['['.implode(',', array_pad($vec, 1024, 0)).']', $e->id]);
}

function mkEstab(string $siren, string $siret, bool $diffusible = true): Establishment
{
    $c = Company::create(['siren' => $siren, 'legal_name' => $siren, 'normalized_name' => $siren, 'status' => 'active', 'is_diffusible' => $diffusible]);

    return Establishment::create(['siret' => $siret, 'company_id' => $c->id, 'name' => $siret, 'normalized_name' => $siret, 'status' => 'active', 'naf_code' => '10.71C', 'city' => 'BORDEAUX']);
}

beforeEach(function (): void {
    $this->seed([RoleSeeder::class, RegionSeeder::class, DepartmentSeeder::class]);
    $this->user = User::factory()->create()->syncRoles('commercial');
    // Fiche de référence : vecteur [1,0,0,...]
    $this->ref = mkEstab('100000001', '10000000100011');
    seedVec($this->ref, [1, 0, 0]);
    // Proche (autre SIREN) : [0.9,0.1,0]
    $this->near = mkEstab('200000002', '20000000200022');
    seedVec($this->near, [0.9, 0.1, 0]);
    // Lointaine (autre SIREN) : [0,1,0]
    $this->far = mkEstab('300000003', '30000000300033');
    seedVec($this->far, [0, 1, 0]);
});

it('renvoie les prospects similaires classés, sans soi ni le même SIREN', function (): void {
    // Succursale du même SIREN que la référence, très proche → doit être exclue.
    $sibling = Establishment::create(['siret' => '10000000100029', 'company_id' => $this->ref->company_id, 'name' => 'sib', 'normalized_name' => 'sib', 'status' => 'active', 'city' => 'BORDEAUX']);
    seedVec($sibling, [1, 0, 0]);
    $id = $this->ref->id;

    $data = $this->actingAs($this->user)->getJson("/api/v1/companies/{$id}/similar")->json('data');

    $sirets = array_column($data, 'siret');
    expect($sirets)->toContain('20000000200022')          // proche présent
        ->and($sirets)->not->toContain('10000000100011')  // pas soi
        ->and($sirets)->not->toContain('10000000100029')  // pas le même SIREN
        ->and($sirets[0])->toBe('20000000200022');         // proche avant lointaine
});

it('exclut les fiches non-diffusibles des résultats (RGPD)', function (): void {
    $hidden = mkEstab('400000004', '40000000400044', diffusible: false);
    seedVec($hidden, [0.95, 0.05, 0]); // plus proche que near, mais non-diffusible
    $id = $this->ref->id;

    $sirets = array_column($this->actingAs($this->user)->getJson("/api/v1/companies/{$id}/similar")->json('data'), 'siret');
    expect($sirets)->not->toContain('40000000400044');
});

it('renvoie 422 si la fiche courante n\'a pas d\'embedding', function (): void {
    $bare = mkEstab('500000005', '50000000500055'); // pas de seedVec
    $this->actingAs($this->user)->getJson("/api/v1/companies/{$bare->id}/similar")->assertStatus(422);
});

it('refuse pour une fiche courante non-diffusible (RGPD)', function (): void {
    $this->ref->company->update(['is_diffusible' => false]);
    $this->actingAs($this->user)->getJson("/api/v1/companies/{$this->ref->id}/similar")->assertStatus(422);
});

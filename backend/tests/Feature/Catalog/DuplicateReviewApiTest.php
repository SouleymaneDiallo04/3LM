<?php

use App\Models\Company;
use App\Models\Establishment;
use App\Models\User;
use Database\Seeders\DepartmentSeeder;
use Database\Seeders\RegionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    $this->seed([RoleSeeder::class, RegionSeeder::class, DepartmentSeeder::class]);
    $this->manager = User::factory()->create()->syncRoles('manager');
    $this->commercial = User::factory()->create()->syncRoles('commercial');

    $companyA = Company::create(['siren' => '111111111', 'legal_name' => 'A', 'normalized_name' => 'a', 'status' => 'active']);
    $companyB = Company::create(['siren' => '222222222', 'legal_name' => 'B', 'normalized_name' => 'b', 'status' => 'active']);

    // Survivant choisi : possède déjà un email, mais pas de téléphone.
    $this->keep = Establishment::create([
        'siret' => '11111111100011', 'company_id' => $companyA->id,
        'name' => 'Boulangerie Martin', 'normalized_name' => 'boulangerie martin',
        'status' => 'active', 'city' => 'BORDEAUX', 'postal_code' => '33000',
        'department_code' => '33', 'email' => 'contact@martin.fr',
    ]);
    // Doublon : porte un téléphone que le survivant n'a pas.
    $this->dup = Establishment::create([
        'siret' => '22222222200011', 'company_id' => $companyB->id,
        'name' => 'Boulangerie Martin', 'normalized_name' => 'boulangerie martin',
        'status' => 'active', 'city' => 'BORDEAUX', 'postal_code' => '33000',
        'department_code' => '33', 'phone' => '+33 5 56 00 00 00',
        'email' => 'autre@martin.fr',
    ]);

    $this->pairId = DB::table('duplicate_reviews')->insertGetId([
        'establishment_a_id' => $this->keep->id,
        'establishment_b_id' => $this->dup->id,
        'similarity' => 0.95, 'status' => 'pending',
        'created_at' => now(), 'updated_at' => now(),
    ]);
});

it('liste les paires en attente avec les deux fiches (permission duplicates.review)', function (): void {
    // Le commercial n'a pas le droit.
    Sanctum::actingAs($this->commercial, ['*']);
    $this->getJson('/api/v1/duplicates')->assertForbidden();

    Sanctum::actingAs($this->manager, ['*']);
    $response = $this->getJson('/api/v1/duplicates');

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(1)
        ->and($response->json('data.0.similarity'))->toBe(0.95)
        ->and($response->json('data.0.a.siret'))->toBe('11111111100011')
        ->and($response->json('data.0.b.siret'))->toBe('22222222200011');
});

it('fusionne : complète le survivant, soft-delete le doublon, trace la décision', function (): void {
    Sanctum::actingAs($this->manager, ['*']);

    $this->postJson("/api/v1/duplicates/{$this->pairId}/merge", ['keep_id' => $this->keep->id])
        ->assertOk();

    $this->keep->refresh();
    // Le téléphone du doublon complète le survivant ; l'email n'est PAS écrasé.
    expect($this->keep->phone)->toBe('+33 5 56 00 00 00')
        ->and($this->keep->email)->toBe('contact@martin.fr')
        ->and($this->dup->fresh()->trashed())->toBeTrue();

    $review = DB::table('duplicate_reviews')->find($this->pairId);
    expect($review->status)->toBe('merged')
        ->and($review->reviewed_by)->toBe($this->manager->id)
        ->and($review->reviewed_at)->not->toBeNull();

    expect(DB::table('audit_logs')->where('action', 'duplicate.merged')->exists())->toBeTrue();
});

it('rejette un keep_id étranger à la paire', function (): void {
    $other = Establishment::create([
        'siret' => '33333333300011', 'company_id' => $this->keep->company_id,
        'name' => 'X', 'normalized_name' => 'x', 'status' => 'active',
        'city' => 'BORDEAUX', 'postal_code' => '33000', 'department_code' => '33',
    ]);

    Sanctum::actingAs($this->manager, ['*']);
    $this->postJson("/api/v1/duplicates/{$this->pairId}/merge", ['keep_id' => $other->id])
        ->assertUnprocessable();

    expect($this->dup->fresh()->trashed())->toBeFalse();
});

it('marque « distinct » : les deux fiches restent, la paire sort de la file', function (): void {
    Sanctum::actingAs($this->manager, ['*']);

    $this->postJson("/api/v1/duplicates/{$this->pairId}/distinct")->assertOk();

    expect($this->keep->fresh()->trashed())->toBeFalse()
        ->and($this->dup->fresh()->trashed())->toBeFalse()
        ->and(DB::table('duplicate_reviews')->find($this->pairId)->status)->toBe('distinct');

    // La file ne renvoie plus la paire résolue.
    expect($this->getJson('/api/v1/duplicates')->json('data'))->toHaveCount(0);
});

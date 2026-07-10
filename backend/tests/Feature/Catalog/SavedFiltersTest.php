<?php

use App\Models\SavedFilter;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->user = User::factory()->create()->syncRoles('commercial');
    $this->colleague = User::factory()->create()->syncRoles('commercial');
});

it('sauvegarde un filtre nommé et le liste (EF-03.4)', function (): void {
    Sanctum::actingAs($this->user, ['*']);

    $create = $this->postJson('/api/v1/saved-filters', [
        'name' => 'Boulangeries Bordeaux avec email',
        'criteria' => ['q' => 'boulangerie', 'city' => 'BORDEAUX', 'has_email' => true],
        'is_shared' => false,
    ]);

    $create->assertCreated();

    $list = $this->getJson('/api/v1/saved-filters');
    expect($list->json('data'))->toHaveCount(1)
        ->and($list->json('data.0.name'))->toBe('Boulangeries Bordeaux avec email')
        ->and($list->json('data.0.criteria.city'))->toBe('BORDEAUX')
        ->and($list->json('data.0.is_owner'))->toBeTrue();
});

it('partage un filtre avec l\'équipe, les privés restent invisibles', function (): void {
    SavedFilter::create([
        'user_id' => $this->colleague->id, 'name' => 'Partagé équipe',
        'criteria' => ['naf' => '45'], 'is_shared' => true,
    ]);
    SavedFilter::create([
        'user_id' => $this->colleague->id, 'name' => 'Privé collègue',
        'criteria' => ['naf' => '10'], 'is_shared' => false,
    ]);

    Sanctum::actingAs($this->user, ['*']);
    $list = $this->getJson('/api/v1/saved-filters');

    expect($list->json('data'))->toHaveCount(1)
        ->and($list->json('data.0.name'))->toBe('Partagé équipe')
        ->and($list->json('data.0.is_owner'))->toBeFalse()
        ->and($list->json('data.0.owner'))->toBe($this->colleague->name);
});

it('supprime ses propres filtres, jamais ceux des autres', function (): void {
    $mine = SavedFilter::create([
        'user_id' => $this->user->id, 'name' => 'À moi',
        'criteria' => [], 'is_shared' => false,
    ]);
    $theirs = SavedFilter::create([
        'user_id' => $this->colleague->id, 'name' => 'Partagé',
        'criteria' => [], 'is_shared' => true,
    ]);

    Sanctum::actingAs($this->user, ['*']);
    $this->deleteJson("/api/v1/saved-filters/{$mine->id}")->assertNoContent();
    $this->deleteJson("/api/v1/saved-filters/{$theirs->id}")->assertForbidden();

    expect(SavedFilter::count())->toBe(1);
});

it('valide le nom et les critères', function (): void {
    Sanctum::actingAs($this->user, ['*']);

    $this->postJson('/api/v1/saved-filters', ['criteria' => []])->assertUnprocessable();
    $this->postJson('/api/v1/saved-filters', ['name' => 'X', 'criteria' => 'pas-un-objet'])
        ->assertUnprocessable();
});

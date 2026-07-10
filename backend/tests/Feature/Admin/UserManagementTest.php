<?php

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->admin = User::factory()->create()->syncRoles('administrateur');
    $this->commercial = User::factory()->create()->syncRoles('commercial');
});

it('réserve la gestion des utilisateurs à la permission users.manage', function (): void {
    $this->getJson('/api/v1/users')->assertUnauthorized();

    Sanctum::actingAs($this->commercial, ['*']);
    $this->getJson('/api/v1/users')->assertForbidden();
});

it('liste les utilisateurs avec rôles et état (EF-10.2)', function (): void {
    Sanctum::actingAs($this->admin, ['*']);

    $response = $this->getJson('/api/v1/users');

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(2);

    $row = collect($response->json('data'))->firstWhere('id', $this->commercial->id);
    expect($row['roles'])->toBe(['commercial'])
        ->and($row['disabled_at'])->toBeNull()
        ->and($row)->toHaveKeys(['name', 'email', 'two_factor_enabled', 'created_at'])
        ->and($row)->not->toHaveKey('password');
});

it('crée un utilisateur avec rôle, qui peut se connecter (EF-10.2)', function (): void {
    Sanctum::actingAs($this->admin, ['*']);

    $response = $this->postJson('/api/v1/users', [
        'name' => 'Awa Ndiaye',
        'email' => 'awa@fbde.local',
        'password' => 'S3cret!fort2026',
        'role' => 'manager',
    ]);

    $response->assertCreated();
    expect($response->json('data.roles'))->toBe(['manager']);

    // Journalisé (§8).
    expect(DB::table('audit_logs')->where('action', 'user.created')->exists())->toBeTrue();

    // Le nouvel utilisateur se connecte.
    $this->postJson('/api/v1/auth/login', [
        'email' => 'awa@fbde.local',
        'password' => 'S3cret!fort2026',
    ])->assertOk();
});

it('rejette une création invalide : email en double, rôle inconnu, mot de passe faible', function (): void {
    Sanctum::actingAs($this->admin, ['*']);

    $this->postJson('/api/v1/users', [
        'name' => 'X', 'email' => $this->commercial->email,
        'password' => 'S3cret!fort2026', 'role' => 'commercial',
    ])->assertUnprocessable();

    $this->postJson('/api/v1/users', [
        'name' => 'X', 'email' => 'x@fbde.local',
        'password' => 'S3cret!fort2026', 'role' => 'super-admin',
    ])->assertUnprocessable();

    $this->postJson('/api/v1/users', [
        'name' => 'X', 'email' => 'x@fbde.local',
        'password' => 'court', 'role' => 'commercial',
    ])->assertUnprocessable();
});

it('change le rôle d\'un utilisateur', function (): void {
    Sanctum::actingAs($this->admin, ['*']);

    $response = $this->patchJson("/api/v1/users/{$this->commercial->id}", [
        'role' => 'manager',
    ]);

    $response->assertOk();
    expect($response->json('data.roles'))->toBe(['manager'])
        ->and($this->commercial->fresh()->hasRole('manager'))->toBeTrue();
});

it('désactive puis réactive un compte : connexion refusée, tokens révoqués', function (): void {
    $this->commercial->createToken('api');
    Sanctum::actingAs($this->admin, ['*']);

    $disable = $this->patchJson("/api/v1/users/{$this->commercial->id}", [
        'disabled' => true,
    ]);

    $disable->assertOk();
    expect($this->commercial->fresh()->disabled_at)->not->toBeNull()
        ->and($this->commercial->tokens()->count())->toBe(0) // tokens révoqués
        ->and(DB::table('audit_logs')->where('action', 'user.disabled')->exists())->toBeTrue();

    // Connexion refusée avec un message explicite.
    $this->postJson('/api/v1/auth/login', [
        'email' => $this->commercial->email,
        'password' => 'password', // mot de passe par défaut de la factory
    ])->assertForbidden();

    // Réactivation : la connexion fonctionne à nouveau.
    Sanctum::actingAs($this->admin, ['*']);
    $this->patchJson("/api/v1/users/{$this->commercial->id}", ['disabled' => false])->assertOk();

    $this->postJson('/api/v1/auth/login', [
        'email' => $this->commercial->email,
        'password' => 'password',
    ])->assertOk();
});

it('refuse qu\'un administrateur se désactive ou se rétrograde lui-même', function (): void {
    Sanctum::actingAs($this->admin, ['*']);

    $this->patchJson("/api/v1/users/{$this->admin->id}", ['disabled' => true])
        ->assertUnprocessable();

    $this->patchJson("/api/v1/users/{$this->admin->id}", ['role' => 'commercial'])
        ->assertUnprocessable();
});

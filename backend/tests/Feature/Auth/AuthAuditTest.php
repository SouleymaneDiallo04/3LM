<?php

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->user = User::factory()->create()->syncRoles('commercial');
});

it('journalise les connexions réussies (§12)', function (): void {
    $this->postJson('/api/v1/auth/login', [
        'email' => $this->user->email,
        'password' => 'password',
    ])->assertOk();

    expect(DB::table('audit_logs')
        ->where('action', 'auth.login')
        ->where('user_id', $this->user->id)
        ->exists())->toBeTrue();
});

it('journalise les tentatives de connexion échouées (§12 — erreurs)', function (): void {
    $this->postJson('/api/v1/auth/login', [
        'email' => $this->user->email,
        'password' => 'mauvais-mot-de-passe',
    ])->assertUnprocessable();

    $entry = DB::table('audit_logs')->where('action', 'auth.login_failed')->first();
    expect($entry)->not->toBeNull()
        ->and($entry->user_id)->toBeNull() // identité non prouvée : pas d'imputation
        ->and(json_decode($entry->payload, true)['email'])->toBe($this->user->email);
});

it('journalise la déconnexion (§12)', function (): void {
    $token = $this->postJson('/api/v1/auth/login', [
        'email' => $this->user->email,
        'password' => 'password',
    ])->json('data.token');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/auth/logout')
        ->assertOk();

    expect(DB::table('audit_logs')
        ->where('action', 'auth.logout')
        ->where('user_id', $this->user->id)
        ->exists())->toBeTrue();
});

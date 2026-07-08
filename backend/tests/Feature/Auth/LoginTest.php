<?php

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\RateLimiter;

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    RateLimiter::clear('test@fbde.local|127.0.0.1');
});

function makeUser(string $role = 'commercial'): User
{
    return User::factory()
        ->create(['email' => 'test@fbde.local', 'password' => 'Secret!2026'])
        ->syncRoles($role);
}

it('délivre un token, les rôles et les permissions à la connexion', function (): void {
    makeUser();

    $response = $this->postJson('/api/v1/auth/login', [
        'email' => 'test@fbde.local',
        'password' => 'Secret!2026',
    ]);

    $response->assertOk()
        ->assertJsonPath('data.user.email', 'test@fbde.local')
        ->assertJsonPath('data.user.roles.0', 'commercial')
        ->assertJsonStructure(['data' => ['user' => ['permissions'], 'token']]);

    expect($response->json('data.user.permissions'))->toContain('companies.view');
});

it('rejette des identifiants invalides', function (): void {
    makeUser();

    $this->postJson('/api/v1/auth/login', [
        'email' => 'test@fbde.local',
        'password' => 'mauvais-mot-de-passe',
    ])->assertUnprocessable()->assertJsonValidationErrors('email');
});

it('verrouille le compte après 5 échecs (EF-10.1)', function (): void {
    makeUser();

    foreach (range(1, 5) as $i) {
        $this->postJson('/api/v1/auth/login', [
            'email' => 'test@fbde.local',
            'password' => 'mauvais',
        ])->assertUnprocessable();
    }

    // La 6e tentative est bloquée même avec le BON mot de passe.
    $response = $this->postJson('/api/v1/auth/login', [
        'email' => 'test@fbde.local',
        'password' => 'Secret!2026',
    ]);

    $response->assertUnprocessable();
    expect($response->json('errors.email.0'))->toContain('attempts');
});

it('refuse l’accès à /me sans authentification', function (): void {
    $this->getJson('/api/v1/auth/me')->assertUnauthorized();
});

it('révoque le token à la déconnexion', function (): void {
    makeUser();

    $token = $this->postJson('/api/v1/auth/login', [
        'email' => 'test@fbde.local',
        'password' => 'Secret!2026',
    ])->json('data.token');

    $headers = ['Authorization' => "Bearer {$token}"];

    $this->getJson('/api/v1/auth/me', $headers)->assertOk();
    $this->postJson('/api/v1/auth/logout', [], $headers)->assertOk();

    // Le token révoqué ne donne plus accès (guards réinitialisés
    // pour forcer une nouvelle résolution du token).
    $this->app['auth']->forgetGuards();
    $this->getJson('/api/v1/auth/me', $headers)->assertUnauthorized();
});

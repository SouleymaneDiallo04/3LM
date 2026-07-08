<?php

use App\Models\User;
use App\Services\Auth\TwoFactorService;
use Database\Seeders\RoleSeeder;
use PragmaRX\Google2FA\Google2FA;

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);

    $this->user = User::factory()
        ->create(['email' => '2fa@fbde.local', 'password' => 'Secret!2026'])
        ->syncRoles('commercial');
});

/** Active et confirme la 2FA pour l'utilisateur de test. */
function activateTwoFactor(User $user): array
{
    $service = app(TwoFactorService::class);
    $enrollment = $service->enable($user);

    $code = app(Google2FA::class)->getCurrentOtp($enrollment['secret']);
    $recoveryCodes = $service->confirm($user->refresh(), $code);

    return [$enrollment['secret'], $recoveryCodes];
}

it('génère un secret et une URI de provisionnement à l’activation', function (): void {
    $response = $this->actingAs($this->user)
        ->postJson('/api/v1/auth/2fa/enable');

    $response->assertOk()
        ->assertJsonStructure(['data' => ['secret', 'provisioning_uri']]);

    expect($response->json('data.provisioning_uri'))->toStartWith('otpauth://totp/');
});

it('confirme l’activation avec un code valide et délivre 8 codes de secours', function (): void {
    $secret = app(TwoFactorService::class)->enable($this->user)['secret'];
    $code = app(Google2FA::class)->getCurrentOtp($secret);

    $response = $this->actingAs($this->user->refresh())
        ->postJson('/api/v1/auth/2fa/confirm', ['code' => $code]);

    $response->assertOk();
    expect($response->json('data.recovery_codes'))->toHaveCount(8);
    expect($this->user->refresh()->hasTwoFactorEnabled())->toBeTrue();
});

it('rejette un code de confirmation invalide', function (): void {
    app(TwoFactorService::class)->enable($this->user);

    $this->actingAs($this->user->refresh())
        ->postJson('/api/v1/auth/2fa/confirm', ['code' => '000000'])
        ->assertUnprocessable();

    expect($this->user->refresh()->hasTwoFactorEnabled())->toBeFalse();
});

it('exige le défi 2FA à la connexion quand la 2FA est active', function (): void {
    activateTwoFactor($this->user);

    $response = $this->postJson('/api/v1/auth/login', [
        'email' => '2fa@fbde.local',
        'password' => 'Secret!2026',
    ]);

    $response->assertOk()
        ->assertJsonPath('data.two_factor_required', true)
        ->assertJsonStructure(['data' => ['challenge_token']]);

    expect($response->json('data.token'))->toBeNull();
});

it('interdit au token de défi d’accéder aux autres endpoints', function (): void {
    activateTwoFactor($this->user);

    $challenge = $this->postJson('/api/v1/auth/login', [
        'email' => '2fa@fbde.local',
        'password' => 'Secret!2026',
    ])->json('data.challenge_token');

    $this->getJson('/api/v1/auth/me', ['Authorization' => "Bearer {$challenge}"])
        ->assertForbidden();
});

it('échange le défi contre un token complet avec un code TOTP valide', function (): void {
    [$secret] = activateTwoFactor($this->user);

    $challenge = $this->postJson('/api/v1/auth/login', [
        'email' => '2fa@fbde.local',
        'password' => 'Secret!2026',
    ])->json('data.challenge_token');

    $code = app(Google2FA::class)->getCurrentOtp($secret);

    $response = $this->postJson('/api/v1/auth/2fa', ['code' => $code], [
        'Authorization' => "Bearer {$challenge}",
    ]);

    $response->assertOk()->assertJsonStructure(['data' => ['user', 'token']]);

    $this->app['auth']->forgetGuards();
    $this->getJson('/api/v1/auth/me', [
        'Authorization' => 'Bearer '.$response->json('data.token'),
    ])->assertOk();
});

it('accepte un code de secours, à usage unique', function (): void {
    [, $recoveryCodes] = activateTwoFactor($this->user);

    $login = fn () => $this->postJson('/api/v1/auth/login', [
        'email' => '2fa@fbde.local',
        'password' => 'Secret!2026',
    ])->json('data.challenge_token');

    // Premier usage : accepté.
    $this->postJson('/api/v1/auth/2fa', ['code' => $recoveryCodes[0]], [
        'Authorization' => 'Bearer '.$login(),
    ])->assertOk();

    // Réutilisation du même code : refusée.
    $this->app['auth']->forgetGuards();
    $this->postJson('/api/v1/auth/2fa', ['code' => $recoveryCodes[0]], [
        'Authorization' => 'Bearer '.$login(),
    ])->assertUnprocessable();
});

it('exige le mot de passe courant pour désactiver la 2FA', function (): void {
    activateTwoFactor($this->user);

    $this->actingAs($this->user->refresh())
        ->postJson('/api/v1/auth/2fa/disable', ['password' => 'mauvais'])
        ->assertUnprocessable();

    $this->actingAs($this->user)
        ->postJson('/api/v1/auth/2fa/disable', ['password' => 'Secret!2026'])
        ->assertOk();

    expect($this->user->refresh()->hasTwoFactorEnabled())->toBeFalse();
});

it('chiffre le secret TOTP en base (§8)', function (): void {
    activateTwoFactor($this->user);

    $raw = \Illuminate\Support\Facades\DB::table('users')
        ->where('id', $this->user->id)
        ->value('two_factor_secret');

    // La valeur brute en base ne doit jamais être le secret en clair.
    expect($raw)->not->toBe($this->user->refresh()->two_factor_secret)
        ->and($raw)->toStartWith('eyJpdi');
});

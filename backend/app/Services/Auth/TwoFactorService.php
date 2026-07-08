<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

/**
 * Double authentification TOTP — RFC 6238 (EF-10.1, §8).
 *
 * Cycle : enable() génère le secret (état « en attente ») → l'utilisateur
 * scanne le QR et confirme avec un code valide via confirm(), qui active la
 * 2FA et délivre les codes de secours à usage unique.
 */
class TwoFactorService
{
    private const RECOVERY_CODE_COUNT = 8;

    public function __construct(private readonly Google2FA $engine) {}

    /**
     * Génère un secret TOTP (non confirmé) et l'URI de provisionnement
     * à encoder en QR code par le client.
     *
     * @return array{secret: string, provisioning_uri: string}
     */
    public function enable(User $user): array
    {
        $secret = $this->engine->generateSecretKey();

        $user->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();

        return [
            'secret' => $secret,
            'provisioning_uri' => $this->engine->getQRCodeUrl(
                config('app.name'),
                $user->email,
                $secret,
            ),
        ];
    }

    /**
     * Confirme l'activation avec un premier code valide et génère
     * les codes de secours.
     *
     * @return list<string>|null les codes de secours, ou null si code invalide
     */
    public function confirm(User $user, string $code): ?array
    {
        if ($user->two_factor_secret === null || ! $this->verifyCode($user, $code)) {
            return null;
        }

        $recoveryCodes = $this->generateRecoveryCodes();

        $user->forceFill([
            'two_factor_recovery_codes' => $recoveryCodes,
            'two_factor_confirmed_at' => now(),
        ])->save();

        return $recoveryCodes;
    }

    /** Désactive complètement la double authentification. */
    public function disable(User $user): void
    {
        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();
    }

    /**
     * Vérifie un code TOTP ou, à défaut, un code de secours
     * (consommé après usage).
     */
    public function verify(User $user, string $code): bool
    {
        if ($this->verifyCode($user, $code)) {
            return true;
        }

        return $this->consumeRecoveryCode($user, $code);
    }

    private function verifyCode(User $user, string $code): bool
    {
        return $user->two_factor_secret !== null
            && $this->engine->verifyKey($user->two_factor_secret, $code) !== false;
    }

    private function consumeRecoveryCode(User $user, string $code): bool
    {
        $codes = $user->two_factor_recovery_codes ?? [];
        $index = array_search($code, $codes, true);

        if ($index === false) {
            return false;
        }

        unset($codes[$index]);

        $user->forceFill([
            'two_factor_recovery_codes' => array_values($codes),
        ])->save();

        return true;
    }

    /** @return list<string> */
    private function generateRecoveryCodes(): array
    {
        return array_map(
            static fn (): string => Str::upper(Str::random(5).'-'.Str::random(5)),
            range(1, self::RECOVERY_CODE_COUNT),
        );
    }
}

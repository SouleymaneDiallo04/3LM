<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Auth\TwoFactorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Gestion de la double authentification TOTP (EF-10.1, §7 : /auth/2fa).
 *
 * Vérification à la connexion : le token de défi (capacité « 2fa:challenge »,
 * durée 5 min) délivré par le login est échangé contre un token complet
 * après validation du code TOTP ou d'un code de secours.
 */
class TwoFactorController extends Controller
{
    public function __construct(private readonly TwoFactorService $twoFactor) {}

    /** Échange le token de défi contre un token API complet. */
    public function verify(Request $request): JsonResponse
    {
        $validated = $request->validate(['code' => ['required', 'string']]);

        $user = $request->user();

        if (! $this->twoFactor->verify($user, $validated['code'])) {
            throw ValidationException::withMessages([
                'code' => 'Code de vérification invalide.',
            ]);
        }

        // Le token de défi est à usage unique : révoqué dès l'échange.
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'data' => [
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'roles' => $user->getRoleNames(),
                    'permissions' => $user->getAllPermissions()->pluck('name'),
                ],
                'token' => $user->createToken('api')->plainTextToken,
            ],
        ]);
    }

    /** Initie l'activation : secret TOTP + URI de provisionnement (QR code). */
    public function enable(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->twoFactor->enable($request->user()),
        ]);
    }

    /** Confirme l'activation avec un premier code valide → codes de secours. */
    public function confirm(Request $request): JsonResponse
    {
        $validated = $request->validate(['code' => ['required', 'string']]);

        $recoveryCodes = $this->twoFactor->confirm($request->user(), $validated['code']);

        if ($recoveryCodes === null) {
            throw ValidationException::withMessages([
                'code' => 'Code de vérification invalide.',
            ]);
        }

        return response()->json([
            'data' => ['recovery_codes' => $recoveryCodes],
        ]);
    }

    /** Désactive la 2FA (confirmation par mot de passe exigée). */
    public function disable(Request $request): JsonResponse
    {
        $request->validate([
            'password' => ['required', 'string', 'current_password'],
        ]);

        $this->twoFactor->disable($request->user());

        return response()->json([
            'data' => ['message' => 'Double authentification désactivée.'],
        ]);
    }
}

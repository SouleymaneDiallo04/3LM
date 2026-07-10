<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Authentification par token Sanctum (§7 : POST /api/v1/auth/login · /logout).
 * La vérification TOTP (EF-10.1) étendra ce flux : login → défi 2FA → token.
 */
class AuthController extends Controller
{
    /**
     * Connexion : renvoie l'utilisateur, ses rôles et un token API.
     * Si la 2FA est active, renvoie un token de défi à échanger
     * contre un token complet via POST /auth/2fa.
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $user = $request->authenticate();

        if ($user->hasTwoFactorEnabled()) {
            $challenge = $user->createToken(
                '2fa-challenge',
                ['2fa:challenge'],
                now()->addMinutes(5),
            )->plainTextToken;

            return response()->json([
                'data' => [
                    'two_factor_required' => true,
                    'challenge_token' => $challenge,
                ],
            ]);
        }

        $token = $user->createToken('api')->plainTextToken;

        // §12 : connexion réussie journalisée (IP et user-agent via Audit).
        Audit::log('auth.login', userId: $user->id);

        return response()->json([
            'data' => [
                'user' => $this->userPayload($user),
                'token' => $token,
            ],
        ]);
    }

    /** Déconnexion : révoque le token utilisé pour la requête courante. */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        Audit::log('auth.logout'); // §12

        return response()->json(['data' => ['message' => 'Déconnecté.']]);
    }

    /** Profil de l'utilisateur authentifié (rôles et permissions inclus). */
    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'data' => ['user' => $this->userPayload($request->user())],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function userPayload(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'roles' => $user->getRoleNames(),
            'permissions' => $user->getAllPermissions()->pluck('name'),
        ];
    }
}

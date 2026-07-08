<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Authentification par token Sanctum (§7 : POST /api/v1/auth/login · /logout).
 * La vérification TOTP (EF-10.1) étendra ce flux : login → défi 2FA → token.
 */
class AuthController extends Controller
{
    /** Connexion : renvoie l'utilisateur, ses rôles et un token API. */
    public function login(LoginRequest $request): JsonResponse
    {
        $request->authenticate();

        /** @var \App\Models\User $user */
        $user = Auth::user();

        $token = $user->createToken('api')->plainTextToken;

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
    private function userPayload(\App\Models\User $user): array
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

<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/**
 * Gestion des utilisateurs par l'administrateur (EF-10.2) : création avec
 * rôle, changement de rôle, désactivation/réactivation. Chaque action
 * sensible est journalisée (§8).
 */
class UserController extends Controller
{
    private const ROLES = ['commercial', 'manager', 'administrateur'];

    public function index(): JsonResponse
    {
        return response()->json([
            'data' => User::query()
                ->with('roles:id,name')
                ->orderBy('name')
                ->get()
                ->map(fn (User $user): array => $this->payload($user)),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', Password::min(12)->letters()->numbers()],
            'role' => ['required', Rule::in(self::ROLES)],
        ]);

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => $validated['password'], // cast hashed
        ]);
        $user->syncRoles($validated['role']);

        Audit::log('user.created', $user, ['role' => $validated['role']]);

        return response()->json(['data' => $this->payload($user)], 201);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $validated = $request->validate([
            'role' => ['sometimes', Rule::in(self::ROLES)],
            'disabled' => ['sometimes', 'boolean'],
        ]);

        // Garde-fou : un administrateur ne se désactive ni ne se rétrograde
        // lui-même — sinon plus personne pour gérer la plateforme.
        if ($user->is($request->user())) {
            throw ValidationException::withMessages([
                'user' => 'Vous ne pouvez pas modifier votre propre compte ici.',
            ]);
        }

        if (array_key_exists('role', $validated)) {
            $user->syncRoles($validated['role']);
            Audit::log('user.role_changed', $user, ['role' => $validated['role']]);
        }

        if (array_key_exists('disabled', $validated)) {
            if ($validated['disabled']) {
                $user->forceFill(['disabled_at' => now()])->save();
                $user->tokens()->delete(); // coupe les sessions en cours
                Audit::log('user.disabled', $user);
            } else {
                $user->forceFill(['disabled_at' => null])->save();
                Audit::log('user.enabled', $user);
            }
        }

        return response()->json(['data' => $this->payload($user->fresh())]);
    }

    /** @return array<string, mixed> */
    private function payload(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'roles' => $user->getRoleNames()->values()->all(),
            'disabled_at' => $user->disabled_at?->toIso8601String(),
            'two_factor_enabled' => $user->hasTwoFactorEnabled(),
            'created_at' => $user->created_at?->toIso8601String(),
        ];
    }
}

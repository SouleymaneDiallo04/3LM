<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Référentiels et comptes initiaux.
     *
     * Le compte administrateur initial est créé à partir des variables
     * d'environnement FBDE_ADMIN_EMAIL / FBDE_ADMIN_PASSWORD — jamais
     * d'identifiants en dur dans le code (§8 Secrets).
     */
    public function run(): void
    {
        $this->call(RoleSeeder::class);

        $email = env('FBDE_ADMIN_EMAIL');
        $password = env('FBDE_ADMIN_PASSWORD');

        if ($email && $password) {
            User::query()
                ->firstOrCreate(['email' => $email], [
                    'name' => 'Administrateur',
                    'password' => $password,
                ])
                ->syncRoles('administrateur');
        }
    }
}

<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Rôles et permissions (EF-10.2) :
 * - Administrateur : tout ;
 * - Manager : gestion d'équipe, exports, statistiques ;
 * - Commercial : recherche, consultation, CRM.
 *
 * Les permissions sont granulaires et alignées sur les endpoints de l'API (§7).
 */
class RoleSeeder extends Seeder
{
    /** Permissions du périmètre v1, groupées par module. */
    private const PERMISSIONS = [
        // Catalogue & recherche
        'companies.view',
        'companies.enrich',
        'searches.create',
        'statistics.view',

        // Exports
        'exports.create',

        // CRM
        'prospects.manage',

        // Plateforme (administration)
        'users.manage',
        'roles.manage',
        'audit-logs.view',
        'team.manage',
    ];

    private const ROLES = [
        'commercial' => [
            'companies.view',
            'companies.enrich',
            'searches.create',
            'statistics.view',
            'exports.create',
            'prospects.manage',
        ],
        'manager' => [
            'companies.view',
            'companies.enrich',
            'searches.create',
            'statistics.view',
            'exports.create',
            'prospects.manage',
            'team.manage',
        ],
        // L'administrateur reçoit toutes les permissions.
        'administrateur' => self::PERMISSIONS,
    ];

    public function run(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        foreach (self::PERMISSIONS as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        foreach (self::ROLES as $role => $permissions) {
            Role::findOrCreate($role, 'web')->syncPermissions($permissions);
        }
    }
}

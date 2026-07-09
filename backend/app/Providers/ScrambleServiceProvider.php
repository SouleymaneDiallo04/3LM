<?php

namespace App\Providers;

use App\Models\User;
use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\SecurityScheme;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Documentation OpenAPI 3.1 (§7, §11) : générée depuis le code par
 * Scramble — spec sur /docs/api.json, interface interactive sur /docs/api.
 * Toute l'API est protégée par token Sanctum (Bearer), déclaré ici pour
 * que « Try it » fonctionne dans l'interface.
 */
class ScrambleServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Accès : libre hors production ; réservé aux administrateurs en prod.
        Gate::define('viewApiDocs', fn (?User $user = null): bool => ! app()->environment('production')
            || ($user?->hasRole('administrateur') ?? false));

        Scramble::configure()
            ->withDocumentTransformers(function (OpenApi $openApi): void {
                $openApi->info->title = 'FBDE — France Business Data Extractor';
                $openApi->secure(SecurityScheme::http('bearer'));
            });
    }
}

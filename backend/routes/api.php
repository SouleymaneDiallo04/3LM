<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\EstablishmentController;
use App\Http\Controllers\Api\V1\ExportController;
use App\Http\Controllers\Api\V1\DuplicateReviewController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\ReferentialController;
use App\Http\Controllers\Api\V1\SavedFilterController;
use App\Http\Controllers\Api\V1\SearchHistoryController;
use App\Http\Controllers\Api\V1\StatisticsController;
use App\Http\Controllers\Api\V1\TwoFactorController;
use App\Http\Controllers\Api\V1\UserController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API v1 (§7 du cahier des charges)
|--------------------------------------------------------------------------
| API versionnée sous /api/v1, authentifiée par token Sanctum.
| Limitation de débit : 60 req/min par token (voir AppServiceProvider).
|
| Capacités de token : le login avec 2FA active délivre un token de défi
| limité à « 2fa:challenge » (5 min) ; seul l'échange via POST /auth/2fa
| délivre un token complet (« * »).
*/

Route::prefix('v1')->group(function (): void {
    Route::prefix('auth')->group(function (): void {
        Route::post('login', [AuthController::class, 'login'])->name('auth.login');

        // Vérification du défi 2FA : accessible avec le token de défi.
        Route::post('2fa', [TwoFactorController::class, 'verify'])
            ->middleware(['auth:sanctum', 'ability:2fa:challenge'])
            ->name('auth.2fa.verify');

        // Endpoints exigeant un token complet.
        Route::middleware(['auth:sanctum', 'abilities:*'])->group(function (): void {
            Route::post('logout', [AuthController::class, 'logout'])->name('auth.logout');
            Route::get('me', [AuthController::class, 'me'])->name('auth.me');

            Route::prefix('2fa')->group(function (): void {
                Route::post('enable', [TwoFactorController::class, 'enable'])->name('auth.2fa.enable');
                Route::post('confirm', [TwoFactorController::class, 'confirm'])->name('auth.2fa.confirm');
                Route::post('disable', [TwoFactorController::class, 'disable'])->name('auth.2fa.disable');
            });
        });
    });

    // Catalogue (§7) : recherche multicritères et fiche. L'endpoint garde le
    // nom « companies » du CDC ; la fiche est l'établissement (ADR 0003).
    Route::middleware(['auth:sanctum', 'abilities:*', 'permission:companies.view'])
        ->group(function (): void {
            Route::get('companies', [EstablishmentController::class, 'index'])->name('companies.index');
            // « facets » et « map » avant {establishment} : sinon le binding les avalerait.
            Route::get('companies/facets', [EstablishmentController::class, 'facets'])
                ->name('companies.facets');
            Route::get('companies/map', [EstablishmentController::class, 'map'])
                ->name('companies.map');
            Route::get('companies/{establishment}', [EstablishmentController::class, 'show'])
                ->name('companies.show');

            // Référentiel régions/départements pour les filtres (EF-01.2).
            Route::get('referentiels', [ReferentialController::class, 'index'])
                ->name('referentiels');

            // Historique des recherches (EF-01.6).
            Route::get('searches', [SearchHistoryController::class, 'index'])->name('searches.index');

            // Filtres sauvegardés et partagés (EF-03.4).
            Route::get('saved-filters', [SavedFilterController::class, 'index'])
                ->name('saved-filters.index');
            Route::post('saved-filters', [SavedFilterController::class, 'store'])
                ->name('saved-filters.store');
            Route::delete('saved-filters/{savedFilter}', [SavedFilterController::class, 'destroy'])
                ->name('saved-filters.destroy');
        });

    // Agrégats du tableau de bord (§7, EF-06).
    Route::middleware(['auth:sanctum', 'abilities:*', 'permission:statistics.view'])
        ->get('statistics', [StatisticsController::class, 'index'])->name('statistics');

    // Revue des doublons (EF-04.2) : managers et administrateurs.
    Route::middleware(['auth:sanctum', 'abilities:*', 'permission:duplicates.review'])
        ->group(function (): void {
            Route::get('duplicates', [DuplicateReviewController::class, 'index'])
                ->name('duplicates.index');
            Route::post('duplicates/{duplicateReview}/merge', [DuplicateReviewController::class, 'merge'])
                ->name('duplicates.merge');
            Route::post('duplicates/{duplicateReview}/distinct', [DuplicateReviewController::class, 'distinct'])
                ->name('duplicates.distinct');
        });

    // Gestion des utilisateurs (EF-10.2) : administrateurs uniquement.
    Route::middleware(['auth:sanctum', 'abilities:*', 'permission:users.manage'])
        ->group(function (): void {
            Route::get('users', [UserController::class, 'index'])->name('users.index');
            Route::post('users', [UserController::class, 'store'])->name('users.store');
            Route::patch('users/{user}', [UserController::class, 'update'])->name('users.update');
        });

    // Notifications interface (EF-10.4) : propres à l'utilisateur connecté.
    Route::middleware(['auth:sanctum', 'abilities:*'])->group(function (): void {
        Route::get('notifications', [NotificationController::class, 'index'])
            ->name('notifications.index');
        Route::post('notifications/read', [NotificationController::class, 'markAllRead'])
            ->name('notifications.read');
    });

    // Exports asynchrones (§7, EF-07).
    Route::middleware(['auth:sanctum', 'abilities:*', 'permission:exports.create'])
        ->group(function (): void {
            Route::post('exports', [ExportController::class, 'store'])->name('exports.store');
            Route::get('exports/{export}', [ExportController::class, 'show'])->name('exports.show');
            Route::get('exports/{export}/download', [ExportController::class, 'download'])
                ->name('exports.download');
        });
});

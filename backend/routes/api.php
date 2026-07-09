<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\EstablishmentController;
use App\Http\Controllers\Api\V1\ExportController;
use App\Http\Controllers\Api\V1\SearchHistoryController;
use App\Http\Controllers\Api\V1\StatisticsController;
use App\Http\Controllers\Api\V1\TwoFactorController;
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
            // « facets » avant {establishment} : sinon le binding l'avalerait.
            Route::get('companies/facets', [EstablishmentController::class, 'facets'])
                ->name('companies.facets');
            Route::get('companies/{establishment}', [EstablishmentController::class, 'show'])
                ->name('companies.show');

            // Historique des recherches (EF-01.6).
            Route::get('searches', [SearchHistoryController::class, 'index'])->name('searches.index');
        });

    // Agrégats du tableau de bord (§7, EF-06).
    Route::middleware(['auth:sanctum', 'abilities:*', 'permission:statistics.view'])
        ->get('statistics', [StatisticsController::class, 'index'])->name('statistics');

    // Exports asynchrones (§7, EF-07).
    Route::middleware(['auth:sanctum', 'abilities:*', 'permission:exports.create'])
        ->group(function (): void {
            Route::post('exports', [ExportController::class, 'store'])->name('exports.store');
            Route::get('exports/{export}', [ExportController::class, 'show'])->name('exports.show');
            Route::get('exports/{export}/download', [ExportController::class, 'download'])
                ->name('exports.download');
        });
});

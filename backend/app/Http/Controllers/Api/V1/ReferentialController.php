<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Region;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

/**
 * Référentiels géographiques (COG INSEE) : régions et leurs départements,
 * pour alimenter les filtres de recherche (EF-01.2). Donnée froide —
 * cache d'un jour, payload 100 % scalaire (contrainte cache Laravel 13).
 */
class ReferentialController extends Controller
{
    public function index(): JsonResponse
    {
        $regions = Cache::remember('referentiels:regions', now()->addDay(), fn (): array => Region::query()
            ->with('departments:code,name,region_code')
            ->orderBy('name')
            ->get()
            ->map(fn (Region $region): array => [
                'code' => $region->code,
                'name' => $region->name,
                'departments' => $region->departments
                    ->map(fn ($d): array => ['code' => $d->code, 'name' => $d->name])
                    ->all(),
            ])
            ->all());

        return response()->json(['data' => ['regions' => $regions]]);
    }
}

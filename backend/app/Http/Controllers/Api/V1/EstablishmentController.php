<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\SearchEstablishmentsRequest;
use App\Http\Resources\EstablishmentResource;
use App\Jobs\RecordSearchJob;
use App\Models\Establishment;
use App\Services\Catalog\EstablishmentSearch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Recherche et consultation des fiches (§7 : GET /api/v1/companies).
 * Recherche synchrone sur la base locale (correctif 3) ; pagination par
 * curseur (§9.2 — pas d'OFFSET profond) ; la construction des filtres
 * est déléguée à EstablishmentSearch, partagée avec les exports.
 */
class EstablishmentController extends Controller
{
    public function __construct(private readonly EstablishmentSearch $search) {}

    public function index(SearchEstablishmentsRequest $request): AnonymousResourceCollection
    {
        $filters = $request->validated();

        // Historisation (EF-01.6) en file — uniquement la requête initiale,
        // pas les pages suivantes du même parcours.
        if (! $request->filled('cursor')) {
            RecordSearchJob::dispatch($request->user()->id, $filters);
        }

        // Tri (EF-03.6) : ordre secondaire sur id pour un curseur stable.
        $sort = $filters['sort'] ?? null;
        $direction = $filters['direction'] ?? 'asc';

        $query = $this->search->buildQuery($filters);

        if ($sort !== null) {
            $query->orderBy('establishments.'.$sort, $direction);
        }

        return EstablishmentResource::collection(
            $query->orderBy('establishments.id')
                ->cursorPaginate((int) ($filters['per_page'] ?? 25)),
        );
    }

    /**
     * Facettes dynamiques (EF-03.3) : compteurs par département et par
     * division NAF sur le résultat filtré courant, cache Redis 5 min (§9.2).
     */
    public function facets(SearchEstablishmentsRequest $request): JsonResponse
    {
        return response()->json([
            'data' => $this->search->facets($request->validated()),
        ]);
    }

    public function show(Establishment $establishment): EstablishmentResource
    {
        $establishment->load('company');

        // Coordonnées extraites en SQL comme dans index().
        $coords = Establishment::query()
            ->whereKey($establishment->id)
            ->selectRaw('ST_X(location::geometry) AS longitude, ST_Y(location::geometry) AS latitude')
            ->first();

        $establishment->longitude = $coords?->longitude;
        $establishment->latitude = $coords?->latitude;

        return new EstablishmentResource($establishment);
    }
}

<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\MapSearchRequest;
use App\Http\Requests\Catalog\SearchEstablishmentsRequest;
use App\Http\Resources\EstablishmentResource;
use App\Jobs\RecordSearchJob;
use App\Models\Establishment;
use App\Services\Catalog\EstablishmentSearch;
use Illuminate\Database\Eloquent\Builder;
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

    /**
     * Carte par emprise (EF-06.3, correctif 16) : points légers dans le
     * rectangle visible, plafonnés ; au-delà, agrégats par cellule calculés
     * en SQL (ST_SnapToGrid) — la carte ne reçoit jamais toute la base.
     * Les déplacements de carte ne sont pas historisés (EF-01.6 vise les
     * recherches, pas la navigation).
     */
    public function map(MapSearchRequest $request): JsonResponse
    {
        $filters = $request->validated();
        [$minLon, $minLat, $maxLon, $maxLat] = $request->bbox();

        $base = fn (): Builder => $this->search->buildQuery($filters)
            ->reorder()
            ->withoutEagerLoads()
            ->select([])
            ->withinBbox($minLon, $minLat, $maxLon, $maxLat);

        $limit = (int) config('fbde.map_points_limit', 2000);

        // Tentative directe en mode points (limit+1 détecte le débordement) :
        // le cas courant — carte zoomée — ne paie qu'une requête indexée,
        // sans count() préalable sur l'emprise entière.
        $points = $base()
            ->select(['establishments.id', 'establishments.siret', 'establishments.name'])
            ->selectRaw('ST_X(location::geometry) AS longitude, ST_Y(location::geometry) AS latitude')
            ->limit($limit + 1)
            ->getQuery()
            ->get();

        if ($points->count() <= $limit) {
            return response()->json([
                'data' => [
                    'mode' => 'points',
                    'total' => $points->count(),
                    'points' => $points->map(fn ($row): array => [
                        'id' => (int) $row->id,
                        'siret' => $row->siret,
                        'name' => $row->name,
                        'longitude' => (float) $row->longitude,
                        'latitude' => (float) $row->latitude,
                    ]),
                ],
            ]);
        }

        // Grille ~16×10 cellules sur l'emprise (≈ la taille d'une pastille à
        // l'écran — validé sur Bordeaux centre : 40×40 noyait la carte sous
        // ~1600 bulles) ; chaque agrégat est posé au barycentre de ses points.
        $cellWidth = max(($maxLon - $minLon) / 16, 1e-6);
        $cellHeight = max(($maxLat - $minLat) / 10, 1e-6);

        $clusters = $base()
            ->selectRaw('avg(ST_X(location::geometry))::float8 AS longitude')
            ->selectRaw('avg(ST_Y(location::geometry))::float8 AS latitude')
            ->selectRaw('count(*) AS count')
            ->groupByRaw('ST_SnapToGrid(location::geometry, ?, ?)', [$cellWidth, $cellHeight])
            ->getQuery()
            ->get()
            ->map(fn ($row): array => [
                'longitude' => (float) $row->longitude,
                'latitude' => (float) $row->latitude,
                'count' => (int) $row->count,
            ]);

        return response()->json([
            'data' => [
                'mode' => 'clusters',
                'total' => $clusters->sum('count'),
                'clusters' => $clusters,
            ],
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

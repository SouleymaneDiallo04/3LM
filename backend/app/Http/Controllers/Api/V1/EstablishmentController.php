<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\SearchEstablishmentsRequest;
use App\Http\Resources\EstablishmentResource;
use App\Models\Establishment;
use App\Services\Ingestion\NameNormalizer;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Recherche et consultation des fiches (§7 : GET /api/v1/companies).
 * Recherche synchrone sur la base locale (correctif 3) ; pagination par
 * curseur (§9.2 — pas d'OFFSET profond) ; coordonnées extraites en SQL
 * (ST_X/ST_Y) pour éviter tout parsing de géométrie côté PHP.
 */
class EstablishmentController extends Controller
{
    public function index(SearchEstablishmentsRequest $request): AnonymousResourceCollection
    {
        $filters = $request->validated();

        $query = Establishment::query()
            ->select('establishments.*')
            ->selectRaw('ST_X(location::geometry) AS longitude, ST_Y(location::geometry) AS latitude')
            ->with('company');

        // Statut : actifs par défaut (la prospection cible des entreprises ouvertes).
        $status = $filters['status'] ?? 'active';
        $query->when($status !== 'all', fn ($q) => $q->where('status', $status));

        // Recherche textuelle sur le nom normalisé (ILIKE, index trigramme).
        $query->when($filters['q'] ?? null, function ($q, string $term): void {
            $normalized = app(NameNormalizer::class)->normalize($term);
            $q->where(function ($sub) use ($normalized, $term): void {
                $sub->where('establishments.normalized_name', 'ILIKE', '%'.($normalized ?? $term).'%')
                    ->orWhereHas('company', fn ($c) => $c->where(
                        'normalized_name', 'ILIKE', '%'.($normalized ?? $term).'%',
                    ));
            });
        });

        $query->when($filters['department'] ?? null, fn ($q, $v) => $q->where('department_code', $v));
        $query->when($filters['city'] ?? null, fn ($q, $v) => $q->where('city', 'ILIKE', $v));
        $query->when($filters['postal_code'] ?? null, fn ($q, $v) => $q->where('postal_code', $v));
        // NAF : code exact (5 car.) ou préfixe de division (2-4 car., EF-01.1).
        $query->when($filters['naf'] ?? null, fn ($q, $v) => strlen($v) >= 5
            ? $q->where('naf_code', $v)
            : $q->where('naf_code', 'LIKE', $v.'%'));

        $query->when(isset($filters['has_email']), fn ($q) => $filters['has_email']
            ? $q->whereNotNull('email') : $q->whereNull('email'));
        $query->when(isset($filters['has_phone']), fn ($q) => $filters['has_phone']
            ? $q->whereNotNull('phone') : $q->whereNull('phone'));
        $query->when(isset($filters['has_website']), fn ($q) => $filters['has_website']
            ? $q->whereNotNull('website') : $q->whereNull('website'));

        // Rayon géographique (EF-01.3).
        $query->when($filters['radius_km'] ?? null, fn ($q, $radius) => $q->withinRadius(
            (float) $filters['lat'],
            (float) $filters['lng'],
            (float) $radius,
        ));

        return EstablishmentResource::collection(
            $query->orderBy('establishments.id')
                ->cursorPaginate((int) ($filters['per_page'] ?? 25)),
        );
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

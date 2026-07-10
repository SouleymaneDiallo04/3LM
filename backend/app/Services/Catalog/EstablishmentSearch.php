<?php

namespace App\Services\Catalog;

use App\Models\Establishment;
use App\Services\Ingestion\NameNormalizer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;

/**
 * Construction de la requête de recherche multicritères (EF-01, EF-03.1a).
 * Partagée entre l'API de recherche et les exports (EF-07.1 : « export du
 * résultat filtré courant ») — mêmes filtres, mêmes résultats, par
 * construction.
 */
class EstablishmentSearch
{
    public function __construct(private readonly NameNormalizer $normalizer) {}

    /**
     * @param  array<string, mixed>  $filters  filtres validés (SearchEstablishmentsRequest)
     * @return Builder<Establishment>
     */
    public function buildQuery(array $filters): Builder
    {
        $query = Establishment::query()
            ->select('establishments.*')
            ->selectRaw('ST_X(location::geometry) AS longitude, ST_Y(location::geometry) AS latitude')
            ->with('company');

        // Statut : actifs par défaut (la prospection cible des entreprises ouvertes).
        $status = $filters['status'] ?? 'active';
        $query->when($status !== 'all', fn ($q) => $q->where('status', $status));

        // Recherche textuelle sur le nom normalisé (ILIKE, index trigramme).
        $query->when($filters['q'] ?? null, function ($q, string $term): void {
            $normalized = $this->normalizer->normalize($term);
            $q->where(function ($sub) use ($normalized, $term): void {
                $sub->where('establishments.normalized_name', 'ILIKE', '%'.($normalized ?? $term).'%')
                    ->orWhereHas('company', fn ($c) => $c->where(
                        'normalized_name', 'ILIKE', '%'.($normalized ?? $term).'%',
                    ));
            });
        });

        $query->when($filters['department'] ?? null, fn ($q, $v) => $q->where('department_code', $v));
        // Région (EF-01.2) : l'union de ses départements — les provinces du
        // CDC belge sont transposées régions/départements (pivot France).
        $query->when($filters['region'] ?? null, fn ($q, $v) => $q->whereIn(
            'department_code',
            fn ($sub) => $sub->select('code')->from('departments')->where('region_code', $v),
        ));
        $query->when($filters['city'] ?? null, fn ($q, $v) => $q->where('city', 'ILIKE', $v));
        $query->when($filters['postal_code'] ?? null, fn ($q, $v) => $q->where('postal_code', $v));
        // NAF : code exact (5 car.) ou préfixe de division (2-4 car., EF-01.1).
        $query->when($filters['naf'] ?? null, fn ($q, $v) => strlen((string) $v) >= 5
            ? $q->where('naf_code', $v)
            : $q->where('naf_code', 'LIKE', $v.'%'));

        $query->when(isset($filters['has_email']), fn ($q) => $filters['has_email']
            ? $q->whereNotNull('email') : $q->whereNull('email'));
        $query->when(isset($filters['has_phone']), fn ($q) => $filters['has_phone']
            ? $q->whereNotNull('phone') : $q->whereNull('phone'));
        $query->when(isset($filters['has_website']), fn ($q) => $filters['has_website']
            ? $q->whereNotNull('website') : $q->whereNull('website'));

        // Recherche avancée (CDC) : note minimale, volume d'avis minimal,
        // taille minimale (les codes tranche INSEE sont ordonnés — la
        // comparaison lexicographique est valide ; « NN » = non renseigné).
        $query->when($filters['min_rating'] ?? null, fn ($q, $v) => $q->where('rating', '>=', $v));
        $query->when($filters['min_reviews'] ?? null, fn ($q, $v) => $q->where('reviews_count', '>=', $v));
        $query->when($filters['min_employees'] ?? null, fn ($q, $v) => $q
            ->whereNotNull('employee_range')
            ->where('employee_range', '!=', 'NN')
            ->where('employee_range', '>=', $v));

        // Rayon géographique (EF-01.3).
        $query->when($filters['radius_km'] ?? null, fn ($q, $radius) => $q->withinRadius(
            (float) $filters['lat'],
            (float) $filters['lng'],
            (float) $radius,
        ));

        return $query;
    }

    /**
     * Facettes dynamiques (EF-03.3) : compteurs par département et par
     * division NAF sur le résultat filtré courant. Agrégats SQL mis en
     * cache 5 minutes (§9.2 — résultats de facettes fréquentes).
     *
     * @param  array<string, mixed>  $filters
     * @return array{departments: list<array{code: string, count: int}>, naf_divisions: list<array{code: string, count: int}>}
     */
    public function facets(array $filters): array
    {
        ksort($filters);
        $key = 'facets:'.md5(json_encode($filters));

        return Cache::remember($key, now()->addMinutes(5), function () use ($filters): array {
            $base = fn (): Builder => $this->buildQuery($filters)
                ->reorder()
                ->withoutEagerLoads()
                ->select([]);

            $departments = $base()
                ->selectRaw('department_code AS code, count(*) AS count')
                ->whereNotNull('department_code')
                ->groupBy('department_code')
                ->orderByDesc('count')
                ->limit(20)
                ->getQuery()
                ->get();

            $nafDivisions = $base()
                ->selectRaw('LEFT(naf_code, 2) AS code, count(*) AS count')
                ->whereNotNull('naf_code')
                ->groupByRaw('LEFT(naf_code, 2)')
                ->orderByDesc('count')
                ->limit(20)
                ->getQuery()
                ->get();

            return [
                'departments' => $departments
                    ->map(fn ($r): array => ['code' => $r->code, 'count' => (int) $r->count])
                    ->all(),
                'naf_divisions' => $nafDivisions
                    ->map(fn ($r): array => ['code' => $r->code, 'count' => (int) $r->count])
                    ->all(),
            ];
        });
    }
}

<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Establishment;
use App\Models\Import;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

/**
 * Agrégats du tableau de bord (§7 : GET /api/v1/statistics).
 * KPI (EF-06.1), répartitions et top villes (EF-06.2), complétude
 * (EF-06.4). Cache Redis 5 minutes (§9.2 — statistiques du dashboard).
 */
class StatisticsController extends Controller
{
    public function index(): JsonResponse
    {
        $data = Cache::remember('statistics:dashboard', now()->addMinutes(5), function (): array {
            $active = Establishment::query()->where('status', 'active');

            $kpis = $active->clone()
                ->selectRaw(<<<'SQL'
                    count(*) AS total_active,
                    count(*) FILTER (WHERE imported_at >= now() - interval '7 days') AS new_7d,
                    count(*) FILTER (WHERE imported_at >= now() - interval '30 days') AS new_30d,
                    count(*) FILTER (WHERE enriched_at IS NOT NULL) AS enriched,
                    count(email) AS with_email,
                    count(phone) AS with_phone,
                    count(website) AS with_website,
                    count(location) AS geolocated,
                    count(*) FILTER (
                        WHERE (email IS NOT NULL OR phone IS NOT NULL)
                          AND GREATEST(enriched_at, crawled_at) >= now() - interval '30 days'
                    ) AS new_contacts_30d
                SQL)
                ->getQuery()
                ->first();

            $total = (int) $kpis->total_active;
            $rate = fn (int $part): float => $total > 0 ? round(100 * $part / $total, 1) : 0.0;

            return [
                'kpis' => [
                    'total_establishments' => (int) Establishment::count(),
                    'active_establishments' => $total,
                    'new_last_7_days' => (int) $kpis->new_7d,
                    'new_last_30_days' => (int) $kpis->new_30d,
                    // Nouveaux contacts (dashboard CDC) : fiches dont un email
                    // ou un téléphone a été collecté (OSM/crawl) sous 30 jours.
                    'new_contacts_30_days' => (int) $kpis->new_contacts_30d,
                    'enriched' => (int) $kpis->enriched,
                    'email_rate' => $rate((int) $kpis->with_email),
                    'phone_rate' => $rate((int) $kpis->with_phone),
                    'website_rate' => $rate((int) $kpis->with_website),
                    'geocoding_rate' => $rate((int) $kpis->geolocated),
                ],
                // Répartitions (EF-06.2) — établissements actifs. Tout le
                // payload mis en cache doit rester scalaire : le cache Redis
                // de Laravel 13 ne désérialise pas les objets
                // (__PHP_Incomplete_Class), d'où les ->all()/toArray().
                'by_department' => $active->clone()
                    ->selectRaw('department_code AS code, count(*) AS count')
                    ->whereNotNull('department_code')
                    ->groupBy('department_code')
                    ->orderByDesc('count')
                    ->limit(20)
                    ->getQuery()->get()
                    ->map(fn ($r): array => ['code' => $r->code, 'count' => (int) $r->count])
                    ->all(),
                'top_cities' => $active->clone()
                    ->selectRaw('city, count(*) AS count')
                    ->whereNotNull('city')
                    ->groupBy('city')
                    ->orderByDesc('count')
                    ->limit(20)
                    ->getQuery()->get()
                    ->map(fn ($r): array => ['city' => $r->city, 'count' => (int) $r->count])
                    ->all(),
                'by_naf_division' => $active->clone()
                    ->selectRaw('LEFT(naf_code, 2) AS code, count(*) AS count')
                    ->whereNotNull('naf_code')
                    ->groupByRaw('LEFT(naf_code, 2)')
                    ->orderByDesc('count')
                    ->limit(10)
                    ->getQuery()->get()
                    ->map(fn ($r): array => ['code' => $r->code, 'count' => (int) $r->count])
                    ->all(),
                // Évolution des imports (EF-06.2).
                'recent_imports' => Import::query()
                    ->latest('id')
                    ->limit(10)
                    ->get(['id', 'source', 'status', 'stats', 'started_at', 'finished_at'])
                    ->map(fn (Import $i): array => $i->toArray())
                    ->all(),
                'generated_at' => now()->toIso8601String(),
            ];
        });

        return response()->json(['data' => $data]);
    }
}

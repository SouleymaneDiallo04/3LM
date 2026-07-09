<?php

namespace App\Jobs;

use App\Models\Search;
use App\Services\Catalog\EstablishmentSearch;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Historisation d'une recherche (EF-01.6) : exécutée en file pour ne
 * jamais ralentir la réponse — le comptage total du résultat (exigé
 * par l'historique) coûte une requête d'agrégation.
 */
class RecordSearchJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /** @param array<string, mixed> $filters */
    public function __construct(
        public readonly int $userId,
        public readonly array $filters,
    ) {}

    public function handle(EstablishmentSearch $search): void
    {
        $count = $search->buildQuery($this->filters)->reorder()->count();

        Search::create([
            'user_id' => $this->userId,
            'keyword' => $this->filters['q'] ?? null,
            'filters' => $this->filters,
            'city' => $this->filters['city'] ?? null,
            'department_code' => $this->filters['department'] ?? null,
            'postal_code' => $this->filters['postal_code'] ?? null,
            'radius_km' => $this->filters['radius_km'] ?? null,
            'results_count' => $count,
            'status' => 'completed',
        ]);
    }
}

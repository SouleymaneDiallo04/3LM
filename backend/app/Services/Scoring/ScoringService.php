<?php

namespace App\Services\Scoring;

use App\Models\Establishment;

/**
 * Scoring 100 % déterministe (note de cadrage, EF-08.1) : aucune IA,
 * uniquement des règles — mêmes entrées, même score. Les pondérations
 * sont versionnées dans config/fbde.php et tracées dans score_factors
 * pour que chaque score soit explicable et auditable.
 */
class ScoringService
{
    /**
     * Palier de classement (CDC §14) déterministe depuis le score commercial :
     * A (≥70) / B (≥40) / C (≥20) / D. Null si la fiche n'a pas de score.
     */
    public function tier(?int $score): ?string
    {
        if ($score === null) {
            return null;
        }

        foreach (config('fbde.scoring.tiers') as $letter => $threshold) {
            if ($score >= $threshold) {
                return $letter;
            }
        }

        return 'D';
    }

    /** Seuil de score minimal d'un palier — pour filtrer les meilleurs prospects. */
    public function tierFloor(string $tier): ?int
    {
        return config("fbde.scoring.tiers.{$tier}");
    }

    public function score(Establishment $establishment): void
    {
        $weights = config('fbde.scoring');

        $reputation = $this->reputation($establishment, $weights['reputation']);
        $commercial = $this->commercial($establishment, $reputation['score'], $weights['commercial']);

        $establishment->update([
            'reputation_score' => $reputation['score'],
            'commercial_score' => $commercial['score'],
            'score_factors' => [
                'version' => $weights['version'],
                'reputation' => $reputation['factors'],
                'commercial' => $commercial['factors'],
            ],
        ]);
    }

    /**
     * Indice de réputation 0-100 : la note du site (JSON-LD) pèse pour
     * l'essentiel, le volume d'avis complète (échelle logarithmique,
     * plafonnée au volume de référence). Pas de note → pas d'indice.
     *
     * @return array{score: int|null, factors: array<string, int>|null}
     */
    private function reputation(Establishment $establishment, array $weights): array
    {
        if ($establishment->rating === null) {
            return ['score' => null, 'factors' => null];
        }

        $ratingPoints = (int) round(
            ((float) $establishment->rating / 5) * $weights['rating_max_points'],
        );

        $volume = (int) ($establishment->reviews_count ?? 0);
        $volumePoints = (int) round(
            min(1.0, log10(1 + $volume) / log10(1 + $weights['reference_reviews']))
                * $weights['volume_max_points'],
        );

        return [
            'score' => $ratingPoints + $volumePoints,
            'factors' => ['rating_points' => $ratingPoints, 'volume_points' => $volumePoints],
        ];
    }

    /**
     * Score commercial 0-100 (EF-08.1) : joignabilité, complétude et
     * réputation — chaque facteur est une règle binaire pondérée, sauf la
     * réputation qui contribue proportionnellement.
     *
     * @return array{score: int, factors: array<string, int>}
     */
    private function commercial(Establishment $establishment, ?int $reputationScore, array $weights): array
    {
        $factors = [
            'email' => $establishment->email !== null ? $weights['email'] : 0,
            'phone' => $establishment->phone !== null ? $weights['phone'] : 0,
            'website' => $establishment->website !== null ? $weights['website'] : 0,
            'geolocated' => $establishment->location !== null ? $weights['geolocated'] : 0,
            // « NN » = effectif non renseigné dans SIRENE.
            'employee_range' => ($establishment->employee_range !== null
                && $establishment->employee_range !== 'NN') ? $weights['employee_range'] : 0,
            'opening_hours' => $establishment->opening_hours !== null ? $weights['opening_hours'] : 0,
            'reputation' => $reputationScore !== null
                ? (int) round($reputationScore * $weights['reputation_ratio'])
                : 0,
        ];

        return ['score' => array_sum($factors), 'factors' => $factors];
    }
}

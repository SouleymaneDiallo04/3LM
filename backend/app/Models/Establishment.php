<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Établissement SIRENE — une ligne par SIRET (ADR 0003).
 * Entité prospectée : adresse, géolocalisation, enrichissements, scores.
 */
class Establishment extends Model
{
    use SoftDeletes;

    /** Alimentée exclusivement par le pipeline d'ingestion. */
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_headquarters' => 'boolean',
            'opening_hours' => 'array',
            'social_links' => 'array',
            'technologies' => 'array',
            'score_factors' => 'array',
            'rating' => 'decimal:1',
            'geo_score' => 'decimal:3',
            'imported_at' => 'datetime',
            'enriched_at' => 'datetime',
            'crawled_at' => 'datetime',
            'ai_summary_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return BelongsTo<Department, $this> */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'department_code', 'code');
    }

    /**
     * Recherche par rayon (EF-01.3) : établissements à moins de $radiusKm km
     * du point donné — ST_DWithin sur l'index GiST (§9.2).
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeWithinRadius(
        Builder $query,
        float $latitude,
        float $longitude,
        float $radiusKm,
    ): Builder {
        return $query->whereRaw(
            'ST_DWithin(location, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography, ?)',
            [$longitude, $latitude, $radiusKm * 1000],
        );
    }

    /**
     * Emprise géographique (EF-06.3, correctif 16) : établissements dont la
     * position intersecte le rectangle donné — ST_Intersects sur l'index
     * GiST ; les non-géolocalisés (location NULL) sont exclus d'office.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeWithinBbox(
        Builder $query,
        float $minLon,
        float $minLat,
        float $maxLon,
        float $maxLat,
    ): Builder {
        return $query->whereRaw(
            'ST_Intersects(location, ST_MakeEnvelope(?, ?, ?, ?, 4326)::geography)',
            [$minLon, $minLat, $maxLon, $maxLat],
        );
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Unité légale SIRENE — une ligne par SIREN (ADR 0003).
 */
class Company extends Model
{
    use SoftDeletes;

    /** Alimentée exclusivement par le pipeline d'ingestion. */
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'incorporated_at' => 'date',
            'is_diffusible' => 'boolean',
            'imported_at' => 'datetime',
        ];
    }

    /** @return HasMany<Establishment, $this> */
    public function establishments(): HasMany
    {
        return $this->hasMany(Establishment::class);
    }
}

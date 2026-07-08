<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Département français (référentiel COG INSEE) — EF-01.2.
 */
class Department extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    /** @return BelongsTo<Region, $this> */
    public function region(): BelongsTo
    {
        return $this->belongsTo(Region::class, 'region_code', 'code');
    }

    /** @return HasMany<Establishment, $this> */
    public function establishments(): HasMany
    {
        return $this->hasMany(Establishment::class, 'department_code', 'code');
    }
}

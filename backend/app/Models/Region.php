<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Région française (référentiel COG INSEE) — EF-01.2.
 */
class Region extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    /** @return HasMany<Department, $this> */
    public function departments(): HasMany
    {
        return $this->hasMany(Department::class, 'region_code', 'code');
    }
}
